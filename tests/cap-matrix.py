"""Run one local CAP experiment; writes only cap_demo.probes, always recovers.
Do not run concurrently with other cluster experiments or catalog changes.
"""
import datetime
import hashlib
import importlib.util
import json
import pathlib
import subprocess
import time
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('cap_network_test', ROOT / 'tests/cap-network.py')
h = importlib.util.module_from_spec(spec)
spec.loader.exec_module(h)


def main():
    run_id = 'matrix-' + datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + uuid.uuid4().hex[:6]
    path = ROOT / 'storage/cap' / (run_id + '.json')
    evidence = {'run_id': run_id, 'started_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                'base_commit': h.run(['git', 'rev-parse', 'HEAD']).strip(), 'source_sha256': {},
                'probes': [], 'statuses': [], 'passed': False}
    for name in ['tests/cap-matrix.py', 'tests/cap-network.py', 'scripts/cap/network.py', 'app/services/CapExperimentService.php']:
        evidence['source_sha256'][name] = hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
    owned = False

    def probe(phase, label, **options):
        payload = {'run_id': run_id, 'key': run_id + '-shared', 'target': 'replica', 'action': 'read',
                   'read_preference': 'primary', 'read_concern': 'majority', 'write_concern': 'majority'} | options
        result = subprocess.run(h.COMPOSE + ['exec', '-T', 'web', 'php', 'scripts/cap/probe.php', 'probe'],
                                cwd=ROOT, input=json.dumps(payload), capture_output=True, text=True, check=True, timeout=20)
        row = json.loads(result.stdout)
        row.update(phase=phase, label=label)
        evidence['probes'].append(row)
        print(f"{phase}: {label}: {'OK' if row['ok'] else 'ERROR'} ({row['elapsed_ms']} ms)", flush=True)
        return row

    def snapshot(phase):
        s = h.status()
        evidence['statuses'].append({'phase': phase, 'status': s})
        return s

    try:
        nodes = h.network.containers()
        before = snapshot('normal')
        assert h.healthy(before), 'Healthy unpartitioned cluster required'
        evidence['firewall_before'] = h.firewall()
        assert not h.network.STATE.exists() and all(h.network.CHAIN not in v for v in evidence['firewall_before'].values())
        evidence['catalog_before'] = h.fingerprints()
        evidence['process_before'] = h.process_state(nodes)
        old = next(n['node'] for n in before['nodes'] if n['role'] == 'PRIMARY')
        evidence['isolated'] = old
        seed = probe('normal', 'seed-shared', action='write', value='before-partition')
        assert seed['ok']
        # Require every node's committed snapshot to contain baseline before cutting network.
        for node in nodes:
            deadline = time.monotonic() + 30
            while True:
                r = probe('normal', 'replication-' + node, target=node, read_preference='secondaryPreferred')
                if r['ok'] and r['result'] and r['result']['operation_id'] == seed['operation_id']:
                    break
                assert time.monotonic() < deadline, 'Baseline replication deadline'
                time.sleep(.5)
        owned = True
        evidence['partition'] = json.loads(h.run(['python3', 'scripts/cap/network.py', 'partition', old]))
        # Timing-dependent: do not assume the former primary still accepts writes.
        for w in ('1', 'majority'):
            probe('transition', 'isolated-write-' + w, target=old, action='write', write_concern=w,
                  key=run_id + '-transition-' + w, value='transition-' + w)
        deadline = time.monotonic() + 90
        while True:
            s = snapshot('election')
            roles = {n['node']: n['role'] for n in s['nodes']}
            if roles[old] != 'PRIMARY' and sum(v == 'PRIMARY' for v in roles.values()) == 1:
                break
            assert time.monotonic() < deadline, 'Election deadline'
            time.sleep(1)
        new = next(n for n, role in roles.items() if role == 'PRIMARY')
        evidence['new_primary'] = new
        evidence['tcp_partition'] = h.tcp(nodes)
        for row in evidence['tcp_partition']:
            assert row['exit_code'] == (124 if old in (row['source'], row['destination']) else 0), row
        deadline = time.monotonic() + 60
        while True:
            latest = probe('settling', 'update-shared', target=new, action='write', value='after-election')
            if latest['ok']:
                break
            assert time.monotonic() < deadline, 'New primary majority readiness deadline'
            time.sleep(1)
        for target in (old, new):
            for w in ('1', 'majority'):
                r = probe('partition', target + '-write-' + w, target=target, action='write', write_concern=w,
                          key=run_id + '-' + target + '-w' + w, value='stable-partition')
                assert r['ok'] == (target == new), r
            for preference, concern in [('secondaryPreferred', 'local'), ('secondaryPreferred', 'majority'),
                                         ('primary', 'linearizable')]:
                r = probe('partition', target + '-' + preference + '-' + concern, target=target,
                          read_preference=preference, read_concern=concern)
                # Record driver/server behavior even for direct primary preference; no inferred routing.
                if r['ok']:
                    assert r['result'], r
                    r['freshness'] = 'latest' if r['result']['operation_id'] == latest['operation_id'] else 'stale'
                if concern == 'linearizable':
                    assert r['ok'] == (target == new), r
                else:
                    assert r['ok'] and r['result']['operation_id'] == (seed if target == old else latest)['operation_id'], r
        for preference in ('primary', 'secondary', 'primaryPreferred', 'secondaryPreferred', 'nearest'):
            r = probe('partition', 'replica-' + preference, read_preference=preference)
            if r['ok'] and r['result']:
                r['freshness'] = 'latest' if r['result']['operation_id'] == latest['operation_id'] else 'stale'
        snapshot('partition-end')
    except BaseException as exc:
        evidence['error'] = repr(exc)
        raise
    finally:
        try:
            if owned:
                evidence['recovery'] = json.loads(h.run(['python3', 'scripts/cap/network.py', 'recover']))
                deadline = time.monotonic() + 90
                while not h.healthy(snapshot('recovery')):
                    assert time.monotonic() < deadline, 'Recovery deadline'
                    time.sleep(1)
                writes = [r for r in evidence['probes'] if r['action'] == 'write']
                keys = sorted({r['key'] for r in writes})
                # Wait for convergence of ALL touched keys on every node, including failed writes.
                deadline = time.monotonic() + 60
                while True:
                    final = {}
                    for key in keys:
                        final[key] = {}
                        for node in nodes:
                            r = probe('recovery', 'reconcile-' + node, key=key, target=node, read_preference='secondaryPreferred')
                            assert r['ok'], r
                            final[key][node] = (r['result'] or {}).get('operation_id')
                    if all(len(set(values.values())) == 1 for values in final.values()):
                        break
                    assert time.monotonic() < deadline, 'Data convergence deadline'
                    time.sleep(1)
                evidence['final_operation_ids'] = final
                evidence['write_outcomes'] = []
                for w in writes:
                    found = next(iter(final[w['key']].values())) == w['operation_id']
                    later = any(x['key'] == w['key'] and x['operation_id'] == next(iter(final[w['key']].values()))
                                for x in writes[writes.index(w) + 1:])
                    outcome = 'present-on-all-nodes' if found else ('superseded' if later else 'absent-after-recovery')
                    evidence['write_outcomes'].append({'operation_id': w['operation_id'], 'label': w['label'],
                                                       'acknowledged': w['ok'], 'write_concern': w['write_concern'], 'outcome': outcome})
                    if w['ok'] and w['write_concern'] == 'majority' and not later:
                        assert found, 'Acknowledged majority write lost'
                evidence['catalog_after'] = h.fingerprints()
                evidence['firewall_after'] = h.firewall()
                evidence['process_after'] = h.process_state(h.network.containers())
                assert evidence['catalog_before'] == evidence['catalog_after']
                assert evidence['firewall_before'] == evidence['firewall_after']
                assert evidence['process_before'] == evidence['process_after']
                evidence['passed'] = 'error' not in evidence
        except BaseException as exc:
            evidence['recovery_error'] = repr(exc)
            raise
        finally:
            evidence['finished_at'] = datetime.datetime.now(datetime.timezone.utc).isoformat()
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(json.dumps(evidence, indent=2) + '\n')
            print(f"Evidence: {path.relative_to(ROOT)}; passed={evidence['passed']}", flush=True)


if __name__ == '__main__':
    main()
