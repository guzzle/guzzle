# Scripted raw HTTP server. GET /<scenario-name>; scenarios defined in scenarios.py
import socket, sys, threading, time, importlib.util, os

spec = importlib.util.spec_from_file_location("scenarios", os.path.join(os.path.dirname(os.path.abspath(__file__)), "scenarios.py"))

def handle(conn):
    try:
        conn.settimeout(10)
        req = b''
        while b'\r\n\r\n' not in req:
            d = conn.recv(65536)
            if not d:
                return
            req += d
        line = req.split(b'\r\n', 1)[0].decode()
        path = line.split(' ')[1]
        name = path.lstrip('/').split('?')[0]
        mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
        steps = mod.SCENARIOS[name]
        for st in steps:
            if st[0] == 'w':
                conn.sendall(st[1])
            elif st[0] == 's':
                time.sleep(st[1])
            elif st[0] == 'rst':
                import struct
                conn.setsockopt(socket.SOL_SOCKET, socket.SO_LINGER, struct.pack('ii', 1, 0))
                conn.close()
                return
        try:
            conn.shutdown(socket.SHUT_WR)
        except OSError:
            pass
        time.sleep(0.2)
    except Exception as e:
        sys.stderr.write('handler error: %r\n' % (e,))
    finally:
        try:
            conn.close()
        except OSError:
            pass

s = socket.socket()
s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
s.bind(('127.0.0.1', 0))
s.listen(64)
open(sys.argv[1], 'w').write(str(s.getsockname()[1]))
while True:
    c, _ = s.accept()
    threading.Thread(target=handle, args=(c,), daemon=True).start()
