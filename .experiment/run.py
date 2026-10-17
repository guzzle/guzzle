import subprocess, sys, os, time, importlib.util
here = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, here)
import scenarios
prefixes = sys.argv[1].split(',')
names = [n for n in scenarios.SCENARIOS if any(n.startswith(p) for p in prefixes) and not n.startswith('stall')]
nf = os.path.join(here, 'names.txt')
open(nf, 'w').write('\n'.join(names) + '\n')
pf = os.path.join(here, 'port.txt')
if os.path.exists(pf):
    os.remove(pf)
srv = subprocess.Popen([sys.executable, '-I', os.path.join(here, 'rawserver.py'), pf])
for _ in range(100):
    if os.path.exists(pf) and open(pf).read().strip():
        break
    time.sleep(0.1)
port = open(pf).read().strip()
try:
    r = subprocess.run([os.environ.get('PHPBIN', 'php'), os.path.join(here, 'probe.php'), port, nf], capture_output=True, text=True, timeout=3000)
    sys.stdout.write(r.stdout + r.stderr)
finally:
    srv.kill()
