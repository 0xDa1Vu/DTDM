"""Local Docker demo only: temporarily isolate the current primary, then recover.

Run without concurrent cluster/network changes or catalog edits. Evidence is saved
under storage/cap. This validates the network tool, not the CAP read/write matrix.
"""
import concurrent.futures
import datetime
import hashlib
import importlib.util
import json
import pathlib
import subprocess
import time

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('network', ROOT / 'scripts/cap/network.py')
network = importlib.util.module_from_spec(spec)
spec.loader.exec_module(network)
COMPOSE = ['bash', 'scripts/demo.sh']


def run(args):
    return subprocess.run(args, cwd=ROOT, capture_output=True, text=True, check=True, timeout=90).stdout


def status():
    return json.loads(run(COMPOSE + ['exec', '-T', 'web', 'php', 'scripts/cap/probe.php', 'status']))


def healthy(s):
    return (s['network'] is None and all(n['reachable'] for n in s['nodes'])
            and sorted(n['role'] for n in s['nodes']) == ['PRIMARY', 'SECONDARY', 'SECONDARY']
            and all(len(n['members_from_this_node']) == 3 and
                    all(m['health'] == 1 and m['state'] in ('PRIMARY', 'SECONDARY')
                        for m in n['members_from_this_node']) for n in s['nodes']))


def firewall():
    return json.loads(run(['python3', 'scripts/cap/network.py', 'inspect']))


def tcp(nodes):
    def check(pair):
        source, dest = pair
        result = subprocess.run(['docker', 'exec', nodes[source]['id'], 'timeout', '2', 'bash', '-c',
                                 'exec 3<>/dev/tcp/' + nodes[dest]['ip'] + '/27017'],
                                capture_output=True, text=True, timeout=15)
        return {'source': source, 'destination': dest, 'exit_code': result.returncode,
                'stderr': result.stderr}
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
        return list(pool.map(check, [(a, b) for a in nodes for b in nodes if a != b]))


def fingerprints():
    php = r'''require 'app/bootstrap.php';
App\Core\MongoDatabase::requireDemo(); $out = [];
foreach (['products', 'categories', 'brands'] as $name) {
    $docs = App\Core\MongoDatabase::db()->selectCollection($name)->find([], ['sort' => ['_id' => 1]])->toArray();
    $out[$name] = ['count' => count($docs), 'sha256' => hash('sha256', json_encode($docs, JSON_THROW_ON_ERROR))];
} echo json_encode($out, JSON_THROW_ON_ERROR);'''
    return json.loads(run(COMPOSE + ['exec', '-T', 'web', 'php', '-r', php]))


def process_state(nodes):
    infos = json.loads(run(['docker', 'inspect'] + [c['id'] for c in nodes.values()]))
    return {i['Id']: {'started_at': i['State']['StartedAt'], 'restarts': i['RestartCount']} for i in infos}


def main():
    evidence = {'started_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                'base_commit': run(['git', 'rev-parse', 'HEAD']).strip(), 'source_sha256': {}, 'passed': False}
    for name in ['scripts/cap/network.py', 'tests/cap-network.py']:
        evidence['source_sha256'][name] = hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
    output = ROOT / 'storage/cap' / ('network-' + datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '.json')
    owned = False
    try:
        nodes = network.containers()  # Enforces demo project and network allowlist.
        evidence['before'] = status()
        evidence['firewall_before'] = firewall()
        assert healthy(evidence['before']), 'Cluster must be healthy with no active partition'
        assert not network.STATE.exists() and all(network.CHAIN not in v for v in evidence['firewall_before'].values())
        evidence['process_before'] = process_state(nodes)
        evidence['catalog_before'] = fingerprints()
        evidence['tcp_before'] = tcp(nodes)
        assert all(r['exit_code'] == 0 for r in evidence['tcp_before'])
        primary = next(n['node'] for n in evidence['before']['nodes'] if n['role'] == 'PRIMARY')
        evidence['isolated'] = primary
        print('PASS baseline; isolating ' + primary, flush=True)
        owned = True
        started = time.monotonic()
        evidence['partition'] = json.loads(run(['python3', 'scripts/cap/network.py', 'partition', primary]))
        evidence['timeline'] = []
        deadline = time.monotonic() + 90
        while True:
            snapshot = status()
            elapsed = round(time.monotonic() - started, 3)
            evidence['timeline'].append({'elapsed_seconds': elapsed, 'status': snapshot})
            roles = {n['node']: n['role'] for n in snapshot['nodes']}
            if roles[primary] != 'PRIMARY' and sum(r == 'PRIMARY' for r in roles.values()) == 1:
                evidence['election_observed_seconds'] = elapsed
                break
            assert time.monotonic() < deadline, 'Election/old primary stepdown deadline exceeded'
            time.sleep(1)
        assert all(n['reachable'] for n in snapshot['nodes']), 'Web observer must reach both sides'
        evidence['tcp_partition'] = tcp(nodes)
        for row in evidence['tcp_partition']:
            expected = 124 if primary in (row['source'], row['destination']) else 0
            assert row['exit_code'] == expected, row
        evidence['firewall_partition'] = firewall()
        print('PASS election and all 6 directed TCP checks during partition', flush=True)
    except BaseException as exc:
        evidence['error'] = repr(exc)
        raise
    finally:
        try:
            if owned:
                evidence['recovery'] = json.loads(run(['python3', 'scripts/cap/network.py', 'recover']))
                deadline = time.monotonic() + 90
                while True:
                    evidence['after'] = status()
                    if healthy(evidence['after']):
                        break
                    assert time.monotonic() < deadline, 'Recovery health deadline exceeded'
                    time.sleep(1)
                evidence['tcp_after'] = tcp(nodes)
                assert all(r['exit_code'] == 0 for r in evidence['tcp_after'])
                evidence['firewall_after'] = firewall()
                assert evidence['firewall_before'] == evidence['firewall_after']
                evidence['process_after'] = process_state(network.containers())
                assert evidence['process_before'] == evidence['process_after'], 'Containers restarted/replaced'
                evidence['catalog_after'] = fingerprints()
                assert evidence['catalog_before'] == evidence['catalog_after'], 'Catalog changed'
                evidence['second_recovery'] = json.loads(run(['python3', 'scripts/cap/network.py', 'recover']))
                assert firewall() == evidence['firewall_before'] and not network.STATE.exists()
                evidence['passed'] = 'error' not in evidence
                print('PASS recovery, unchanged catalog/processes/firewall, repeated recovery', flush=True)
        except BaseException as exc:
            evidence['recovery_error'] = repr(exc)
            print('Recovery verification failed. Inspect cluster and run: python3 scripts/cap/network.py recover', flush=True)
            raise
        finally:
            evidence['finished_at'] = datetime.datetime.now(datetime.timezone.utc).isoformat()
            output.parent.mkdir(parents=True, exist_ok=True)
            output.write_text(json.dumps(evidence, indent=2) + '\n')
            print('Evidence: ' + str(output.relative_to(ROOT)), flush=True)


if __name__ == '__main__':
    main()
