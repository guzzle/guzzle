import gzip, zlib

def body(n):
    return b''.join(b'%07d\n' % i for i in range(n))

D = body(2000)           # 16000 bytes plain
D1, D2 = D[:8000], D[8000:]

def gz(data, mtime=0):
    return gzip.compress(data, mtime=mtime)

def gz_parts(parts):
    c = zlib.compressobj(6, zlib.DEFLATED, 31)
    out = []
    for i, p in enumerate(parts):
        out.append(c.compress(p) + c.flush(zlib.Z_SYNC_FLUSH if i < len(parts) - 1 else zlib.Z_FINISH))
    return out

def zl(data):
    return zlib.compress(data)

def chunk(data, size=1000):
    out = b''
    for i in range(0, len(data), size):
        piece = data[i:i + size]
        out += b'%x\r\n' % len(piece) + piece + b'\r\n'
    return out

def chunk_end():
    return b'0\r\n\r\n'

def hdr(lines):
    return ('HTTP/1.1 200 OK\r\n' + ''.join(l + '\r\n' for l in lines) + 'Connection: close\r\n\r\n').encode()

G = gz(D)
G1, G2 = gz_parts([D1, D2])
STALL = 2.5

SCENARIOS = {}

# Issue 1: stalls mid-body
SCENARIOS['stall-cl'] = [('w', hdr(['Content-Length: %d' % len(D)]) + D1), ('s', STALL), ('w', D2)]
SCENARIOS['stall-close'] = [('w', hdr([]) + D1), ('s', STALL), ('w', D2)]
SCENARIOS['stall-chunked'] = [('w', hdr(['Transfer-Encoding: chunked']) + chunk(D1)), ('s', STALL), ('w', chunk(D2) + chunk_end())]
SCENARIOS['stall-gzip-cl'] = [('w', hdr(['Content-Encoding: gzip', 'Content-Length: %d' % (len(G1) + len(G2))]) + G1), ('s', STALL), ('w', G2)]
SCENARIOS['stall-gzip-chunked'] = [('w', hdr(['Content-Encoding: gzip', 'Transfer-Encoding: chunked']) + chunk(G1)), ('s', STALL), ('w', chunk(G2) + chunk_end())]
SCENARIOS['stall2-cl'] = [('w', hdr(['Content-Length: %d' % len(D)]) + D[:5000]), ('s', STALL), ('w', D[5000:10000]), ('s', STALL), ('w', D[10000:])]

# Issue 2: transfer codings
SCENARIOS['te-chunked'] = [('w', hdr(['Transfer-Encoding: chunked']) + chunk(D) + chunk_end())]
SCENARIOS['te-gzip-chunked'] = [('w', hdr(['Transfer-Encoding: gzip, chunked']) + chunk(G) + chunk_end())]
SCENARIOS['te-gzip-chunked-2lines'] = [('w', hdr(['Transfer-Encoding: gzip', 'Transfer-Encoding: chunked']) + chunk(G) + chunk_end())]
SCENARIOS['te-gzip'] = [('w', hdr(['Transfer-Encoding: gzip']) + G)]
SCENARIOS['te-chunked-gzip'] = [('w', hdr(['Transfer-Encoding: chunked, gzip']) + gz(chunk(D) + chunk_end()))]
SCENARIOS['te-chunked-gzip-2lines'] = [('w', hdr(['Transfer-Encoding: chunked', 'Transfer-Encoding: gzip']) + gz(chunk(D) + chunk_end()))]
SCENARIOS['te-chunkedx'] = [('w', hdr(['Transfer-Encoding: chunkedx']) + chunk(D) + chunk_end())]
SCENARIOS['te-comma-chunked'] = [('w', hdr(['Transfer-Encoding: , chunked']) + chunk(D) + chunk_end())]
SCENARIOS['te-chunked-chunked'] = [('w', hdr(['Transfer-Encoding: chunked, chunked']) + chunk(D) + chunk_end())]
SCENARIOS['te-identity'] = [('w', hdr(['Transfer-Encoding: identity']) + D)]
SCENARIOS['te-xfoo-chunked'] = [('w', hdr(['Transfer-Encoding: x-foo, chunked']) + chunk(D) + chunk_end())]
SCENARIOS['te-gzip-chunked-ce-gzip'] = [('w', hdr(['Content-Encoding: gzip', 'Transfer-Encoding: gzip, chunked']) + chunk(gz(G)) + chunk_end())]
SCENARIOS['te-UPPER'] = [('w', hdr(['Transfer-Encoding: CHUNKED']) + chunk(D) + chunk_end())]

# Issue 3: gzip integrity
def variants(name, data):
    SCENARIOS[name + '-cl'] = [('w', hdr(['Content-Encoding: gzip', 'Content-Length: %d' % len(data)]) + data)]
    SCENARIOS[name + '-chunked'] = [('w', hdr(['Content-Encoding: gzip', 'Transfer-Encoding: chunked']) + chunk(data) + chunk_end())]
    SCENARIOS[name + '-close'] = [('w', hdr(['Content-Encoding: gzip']) + data)]

def corrupt(data, pos):
    b = bytearray(data)
    for i in range(pos, pos + 16):
        b[i] ^= 0x55
    return bytes(b)

def bad_crc(data):
    b = bytearray(data)
    b[-8] ^= 0xff
    return bytes(b)

def bad_isize(data):
    b = bytearray(data)
    b[-1] ^= 0x01
    return bytes(b)

variants('gz-ok', G)
variants('gz-trunc-half', G[:len(G) // 2])
variants('gz-trunc-10', G[:10])
variants('gz-no-trailer', G[:-8])
variants('gz-trunc-trailer4', G[:-4])
variants('gz-bad-crc', bad_crc(G))
variants('gz-bad-isize', bad_isize(G))
variants('gz-corrupt-mid', corrupt(G, len(G) // 2))
variants('gz-trailing-garbage', G + b'garbage!')
variants('gz-multi-member', gz(D1) + gz(D2))
variants('gz-not-gzip', D)
variants('gz-empty', b'')
variants('gz-sync-noend', G1)

# HTTP-level truncation and malformed chunking
H = len(G) // 2
SCENARIOS['cut-gz-cl'] = [('w', hdr(['Content-Encoding: gzip', 'Content-Length: %d' % len(G)]) + G[:H])]
SCENARIOS['cut-gz-chunked'] = [('w', hdr(['Content-Encoding: gzip', 'Transfer-Encoding: chunked']) + chunk(G[:H]))]
SCENARIOS['cut-gz-chunked-mid'] = [('w', hdr(['Content-Encoding: gzip', 'Transfer-Encoding: chunked']) + chunk(G)[:H])]
SCENARIOS['cut-plain-cl'] = [('w', hdr(['Content-Length: %d' % len(D)]) + D[:8000])]
SCENARIOS['cut-plain-chunked'] = [('w', hdr(['Transfer-Encoding: chunked']) + chunk(D1))]
SCENARIOS['cut-plain-chunked-mid'] = [('w', hdr(['Transfer-Encoding: chunked']) + chunk(D)[:8100])]
SCENARIOS['cut-plain-chunked-nocrlf'] = [('w', hdr(['Transfer-Encoding: chunked']) + chunk(D) + b'0\r\n')]
SCENARIOS['cut-bad-chunk-size'] = [('w', hdr(['Transfer-Encoding: chunked']) + chunk(D1) + b'zz\r\n' + D2 + b'\r\n0\r\n\r\n')]
SCENARIOS['cut-bad-chunk-first'] = [('w', hdr(['Transfer-Encoding: chunked']) + b'zz\r\n' + D + b'\r\n0\r\n\r\n')]
SCENARIOS['cut-chunk-trailer'] = [('w', hdr(['Transfer-Encoding: chunked']) + chunk(D) + b'0\r\nX-Trailer: 1\r\n\r\n')]
SCENARIOS['cut-chunk-ext'] = [('w', hdr(['Transfer-Encoding: chunked']) + chunk(D).replace(b'3e8\r\n', b'3e8;foo=bar\r\n') + chunk_end())]
SCENARIOS['cut-chunk-after-end'] = [('w', hdr(['Transfer-Encoding: chunked']) + chunk(D) + chunk_end() + b'EXTRA-BYTES')]

# Chunked semantics (ck-) and Transfer-Encoding list (tl-) fixtures. Body
# payload P is small and chunk boundaries are explicit.
P = b'hello world'
def te(v):
    return hdr(['Transfer-Encoding: ' + v])
OK = b'5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n'
CK = {
    'ck-ok': OK,
    'ck-ok-upper-hex': b'5\r\nhello\r\n6\r\n world\r\nA\r\n0123456789\r\n0\r\n\r\n',
    'ck-leading-zeros': b'0005\r\nhello\r\n00000000000000000006\r\n world\r\n0\r\n\r\n',
    'ck-last-chunk-zeros': b'5\r\nhello\r\n6\r\n world\r\n000\r\n\r\n',
    'ck-ext': b'5;a=b\r\nhello\r\n6 ; c="d;e"\r\n world\r\n0;z\r\n\r\n',
    'ck-ext-bws-tab': b'5\t;a\r\nhello\r\n6\r\n world\r\n0\r\n\r\n',
    'ck-size-trailing-space': b'5 \r\nhello\r\n6\r\n world\r\n0\r\n\r\n',
    'ck-size-leading-space': b' 5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n',
    'ck-size-junk-after': b'5x\r\nhello\r\n6\r\n world\r\n0\r\n\r\n',
    'ck-size-empty': b'\r\nhello\r\n0\r\n\r\n',
    'ck-size-0x': b'0x5\r\nhello\r\n0\r\n\r\n',
    'ck-size-neg': b'-5\r\nhello\r\n0\r\n\r\n',
    'ck-size-plus': b'+5\r\nhello\r\n0\r\n\r\n',
    'ck-size-invalid-first': b'zz\r\nhello\r\n0\r\n\r\n',
    'ck-size-invalid-later': b'5\r\nhello\r\nzz\r\n world\r\n0\r\n\r\n',
    'ck-size-16hex-huge': b'5\r\nhello\r\nffffffffffffffff\r\n world\r\n0\r\n\r\n',
    'ck-size-17hex': b'5\r\nhello\r\n10000000000000000\r\n world\r\n0\r\n\r\n',
    'ck-size-int-max': b'5\r\nhello\r\n7fffffffffffffff\r\n world',
    'ck-size-2pow63': b'5\r\nhello\r\n8000000000000000\r\n world',
    'ck-bare-lf-size': b'5\nhello\r\n6\n world\r\n0\n\r\n',
    'ck-bare-lf-data': b'5\r\nhello\n6\r\n world\n0\r\n\r\n',
    'ck-bare-lf-all': b'5\nhello\n6\n world\n0\n\n',
    'ck-bare-cr-size': b'5\rhello\r\n0\r\n\r\n',
    'ck-data-no-crlf': b'5\r\nhelloXX6\r\n world\r\n0\r\n\r\n',
    'ck-data-too-long': b'5\r\nhello!\r\n0\r\n\r\n',
    'ck-data-crlf-missing-eof': b'5\r\nhello',
    'ck-eof-after-chunk': b'5\r\nhello\r\n',
    'ck-eof-mid-data': b'5\r\nhel',
    'ck-eof-mid-size': b'5\r\nhello\r\n6',
    'ck-eof-after-size-cr': b'5\r\nhello\r\n6\r',
    'ck-eof-after-last-chunk': b'5\r\nhello\r\n0\r\n',
    'ck-eof-after-last-chunk-cr': b'5\r\nhello\r\n0\r\n\r',
    'ck-eof-in-trailer': b'5\r\nhello\r\n0\r\nX-T: 1\r\n',
    'ck-empty-body': b'',
    'ck-only-last-chunk': b'0\r\n\r\n',
    'ck-trailer': b'5\r\nhello\r\n0\r\nX-T: 1\r\nX-U: 2\r\n\r\n',
    'ck-trailer-no-colon': b'5\r\nhello\r\n0\r\nbogus trailer\r\n\r\n',
    'ck-trailer-bare-lf': b'5\r\nhello\r\n0\r\nX-T: 1\n\n',
    'ck-after-terminator': b'5\r\nhello\r\n0\r\n\r\nEXTRA',
    'ck-after-terminator-chunk': b'5\r\nhello\r\n0\r\n\r\n5\r\nworld\r\n0\r\n\r\n',
    'ck-many-ext-long': b'5;' + b'a' * 20000 + b'\r\nhello\r\n0\r\n\r\n',
}
for name, raw in CK.items():
    SCENARIOS[name] = [('w', te('chunked') + raw)]
SCENARIOS['ck-http10-te'] = [('w', b'HTTP/1.0 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n' + OK)]
SCENARIOS['ck-http10-te-raw'] = [('w', b'HTTP/1.0 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n' + P)]

TL = {
    'tl-chunked': 'chunked',
    'tl-Chunked': 'Chunked',
    'tl-chunked-ows': ' chunked \t',
    'tl-empty-elem-before': ', chunked',
    'tl-empty-elem-after': 'chunked,',
    'tl-chunked-chunked': 'chunked, chunked',
    'tl-identity-chunked': 'identity, chunked',
    'tl-chunked-identity': 'chunked, identity',
    'tl-gzip-chunked': 'gzip, chunked',
    'tl-chunked-gzip': 'chunked, gzip',
    'tl-chunkedx': 'chunkedx',
    'tl-chunked-param': 'chunked;q=1',
    'tl-chunked-param-ows': 'chunked ; foo=bar',
    'tl-xfoo': 'x-foo',
    'tl-identity': 'identity',
    'tl-empty': '',
    'tl-comma-only': ',',
    'tl-quoted': '"chunked"',
}
for name, v in TL.items():
    body = OK if 'chunked' in v.lower() and not v.lower().endswith('gzip') and v != 'chunked, identity' else P
    SCENARIOS[name] = [('w', te(v) + body)]
SCENARIOS['tl-two-lines-chunked-chunked'] = [('w', hdr(['Transfer-Encoding: chunked', 'Transfer-Encoding: chunked']) + OK)]
SCENARIOS['tl-two-lines-gzip-chunked'] = [('w', hdr(['Transfer-Encoding: gzip', 'Transfer-Encoding: chunked']) + OK)]
SCENARIOS['tl-two-lines-chunked-gzip'] = [('w', hdr(['Transfer-Encoding: chunked', 'Transfer-Encoding: gzip']) + P)]
SCENARIOS['tl-two-lines-identity-chunked'] = [('w', hdr(['Transfer-Encoding: identity', 'Transfer-Encoding: chunked']) + OK)]
SCENARIOS['tl-two-lines-chunked-empty'] = [('w', hdr(['Transfer-Encoding: chunked', 'Transfer-Encoding: ']) + OK)]
