"""Partition only peers in the dtdm_demo Compose project; never stop mongod."""
import datetime
import ipaddress
import json
import pathlib
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
NODES = ('mongo1', 'mongo2', 'mongo3')
STATE = ROOT / 'storage/cap/network.json'
CHAIN = 'DTDM_CAP'

def run(args, check=True):
    return subprocess.run(args, cwd=ROOT, check=check, capture_output=True, text=True)

def containers():
    result = {}
    for node in NODES:
        cid = run(['bash', 'scripts/demo.sh', 'ps', '-q', node]).stdout.strip()
        if not cid:
            raise RuntimeError(f'{node} is not running')
        info = json.loads(run(['docker', 'inspect', cid]).stdout)[0]
        labels = info['Config']['Labels']
        if labels.get('com.docker.compose.project') != 'dtdm_demo' or labels.get('com.docker.compose.service') != node:
            raise RuntimeError('Refusing a container outside the demo project')
        addresses = info['NetworkSettings']['Networks']
        if set(addresses) != {'dtdm_demo_default'}:
            raise RuntimeError('Unexpected network: refusing to modify it')
        addr = str(ipaddress.IPv4Address(addresses['dtdm_demo_default']['IPAddress']))
        result[node] = {'id': cid, 'ip': addr}
    return result

def ipt(container, *args, check=True):
    return run(['docker', 'exec', container['id'], 'iptables', '-w', '3', *args], check)

def recover(nodes):
    for container in nodes.values():
        if ipt(container, '-S', CHAIN, check=False).returncode != 0:
            continue
        for parent in ('INPUT', 'OUTPUT'):
            while ipt(container, '-C', parent, '-j', CHAIN, check=False).returncode == 0:
                ipt(container, '-D', parent, '-j', CHAIN)
        ipt(container, '-F', CHAIN)
        ipt(container, '-X', CHAIN)
    STATE.unlink(missing_ok=True)

def main():
    action = sys.argv[1] if len(sys.argv) > 1 else ''
    if action not in ('partition', 'recover', 'inspect'):
        raise SystemExit('Usage: python3 scripts/cap/network.py partition mongoN | recover | inspect')
    nodes = containers()
    if action == 'recover':
        recover(nodes)
        print(json.dumps({'recovered': True, 'at': datetime.datetime.now(datetime.timezone.utc).isoformat()}))
        return
    if action == 'inspect':
        print(json.dumps({name: ipt(c, '-S').stdout for name, c in nodes.items()}, indent=2))
        return
    target = sys.argv[2] if len(sys.argv) > 2 else ''
    if target not in NODES:
        raise SystemExit('Specify mongo1, mongo2 or mongo3 explicitly after observing the current primary.')
    if STATE.exists() or any(ipt(c, '-S', CHAIN, check=False).returncode == 0 for c in nodes.values()):
        raise RuntimeError('A partition already exists; recover first.')
    STATE.parent.mkdir(parents=True, exist_ok=True)
    manifest = {'isolated': target, 'created_at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'nodes': nodes}
    STATE.write_text(json.dumps(manifest, indent=2))
    try:
        for name, container in nodes.items():
            ipt(container, '-N', CHAIN)
            ipt(container, '-I', 'INPUT', '1', '-j', CHAIN)
            ipt(container, '-I', 'OUTPUT', '1', '-j', CHAIN)
            peers = [n for n in NODES if n != target] if name == target else [target]
            for peer in peers:
                ipt(container, '-A', CHAIN, '-s', nodes[peer]['ip'], '-j', 'DROP')
                ipt(container, '-A', CHAIN, '-d', nodes[peer]['ip'], '-j', 'DROP')
        manifest['rules_applied'] = True
        STATE.write_text(json.dumps(manifest, indent=2))
    except BaseException:
        recover(nodes)
        raise
    print(json.dumps(manifest, indent=2))

if __name__ == '__main__':
    main()
