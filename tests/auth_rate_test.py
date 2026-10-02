#!/usr/bin/env python3
"""Real independent PHP processes exercise both credential-verification call sites."""
import contextlib
import hashlib
import json
import os
import pathlib
import sqlite3
import subprocess
import tempfile
import time
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')
PHP_COMMAND = [PHP, '-d', 'display_errors=stderr', '-d', 'log_errors=0', str(ROOT/'tests/auth-rate-fixture.php')]
CHECKS = 0
FAILURES = []

def check(condition, label):
    global CHECKS
    CHECKS += 1
    if not condition:
        FAILURES.append(label)
    print('PASS' if condition else 'FAIL', label, flush=True)

def command(task):
    run = subprocess.run(PHP_COMMAND,
                         input=json.dumps(task), text=True, capture_output=True, timeout=30,
                         env={**os.environ, 'SHARE_STORAGE_DIR': str(pathlib.Path(task['root'])/'storage')})
    if run.returncode or run.stderr:
        raise RuntimeError(f'PHP fixture failed ({run.returncode}): '+run.stderr+run.stdout)
    return json.loads(run.stdout)

@contextlib.contextmanager
def fixture():
    with tempfile.TemporaryDirectory(prefix='share-auth-') as tmp:
        root = pathlib.Path(tmp)
        (root/'files').mkdir(); (root/'storage').mkdir()
        for name in ['protected.txt', 'other.txt']:
            (root/'files'/name).write_bytes(b'Synthetic authentication fixture')
        ids = command({'mode': 'init', 'root': tmp})
        db = sqlite3.connect(root/'storage/share.sqlite', timeout=10)
        try:
            yield root, db, ids
        finally:
            db.close()

def buckets(mode, ident, ip):
    hashed = hashlib.sha256(ip.encode()).hexdigest()
    return [('admin:'+hashed, 8), ('admin:global', 100)] if mode == 'admin' else [
        (f'unlock:file:{ident}:{hashed}', 8), ('unlock:ip:'+hashed, 40), ('unlock:global', 500)]

def seed(db, key, count):
    db.execute('INSERT OR REPLACE INTO rate_limits VALUES(?,?,?)', (key, count, int(time.time())+900))
    db.commit()

def attempts(db, key):
    row = db.execute('SELECT attempts FROM rate_limits WHERE bucket=?', (key,)).fetchone()
    return row[0] if row else 0

def race(root, tasks):
    batch = uuid.uuid4().hex
    processes = []
    try:
        for index, task in enumerate(tasks):
            proc = subprocess.Popen(PHP_COMMAND,
                                    stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                    stderr=subprocess.PIPE, text=True,
                                    env={**os.environ, 'SHARE_STORAGE_DIR': str(root/'storage')})
            proc.stdin.write(json.dumps({**task, 'root': str(root), 'barrier': batch, 'index': index}))
            proc.stdin.close(); proc.stdin = None
            processes.append(proc)
        deadline = time.monotonic()+20
        while len(list(root.glob(batch+'-ready-*'))) != len(tasks):
            if time.monotonic() > deadline or any(p.poll() is not None for p in processes):
                raise RuntimeError('Concurrent auth workers did not reach barrier')
            time.sleep(.005)
        (root/(batch+'-go')).touch()
        results = []
        for proc in processes:
            stdout, stderr = proc.communicate(timeout=30)
            if proc.returncode or stderr:
                raise RuntimeError('Concurrent PHP fixture failed: '+stderr+stdout)
            results.append(json.loads(stdout))
        return results
    finally:
        for proc in processes:
            if proc.poll() is None:
                proc.kill(); proc.wait()

def blocked_verifier(root, outcome='true'):
    marker = uuid.uuid4().hex
    task = {'mode': 'verify', 'root': str(root), 'keys': ['test:first', 'test:second'],
            'limits': [8, 8], 'outcome': outcome, 'marker': marker}
    proc = subprocess.Popen(PHP_COMMAND, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, text=True)
    proc.stdin.write(json.dumps(task)); proc.stdin.close(); proc.stdin = None
    deadline = time.monotonic()+10
    while not (root/marker).exists():
        if time.monotonic() > deadline or proc.poll() is not None:
            proc.kill(); stdout, stderr = proc.communicate()
            raise RuntimeError('Verifier did not start: '+stderr+stdout)
        time.sleep(.005)
    return proc, root/(marker+'-go')

def finish_verifier(proc, gate):
    gate.touch()
    stdout, stderr = proc.communicate(timeout=20)
    if proc.returncode or stderr:
        raise RuntimeError('Verifier failed: '+stderr+stdout)
    return json.loads(stdout)

def main():
    # A costly synthetic hash keeps every old check-before-verify worker in the vulnerable window.
    for mode, selected in [('admin', 0), ('admin', 1), ('file', 0), ('file', 1), ('file', 2)]:
        with fixture() as (root, db, ids):
            ip = '203.0.113.8'
            keys = buckets(mode, ids[0], ip)
            key, limit = keys[selected]
            seed(db, key, limit-1)
            results = race(root, [{'mode': mode, 'id': ids[0], 'ip': ip} for _ in range(8)])
            wrong = 'login' if mode == 'admin' else 'password'
            allowed = sum(r['reason'] == wrong for r in results)
            blocked = sum(r['reason'] == 'rate_limit' and r['status'] == 429 for r in results)
            print(f'OBSERVED {mode} bucket {selected}: bad-password={allowed}, blocked={blocked}', flush=True)
            check(allowed == 1 and blocked == 7, f'{mode} bucket {selected} last slot admits exactly one password guess')
            check(attempts(db, key) == limit, f'{mode} bucket {selected} counter never exceeds its limit')
            for other, _ in keys:
                if other != key:
                    check(attempts(db, other) == 1, f'{mode} rejected race has no partial charge in another bucket')
            check(db.execute('SELECT COUNT(*) FROM sessions').fetchone()[0] == 0,
                  f'{mode} wrong passwords never create download candidates')
            check(db.execute('SELECT SUM(public_count) FROM files').fetchone()[0] == 0,
                  f'{mode} wrong passwords never consume public download counts')

    # Shared buckets must also constrain distinct per-file/per-IP buckets.
    for mode, selected in [('admin', 1), ('file', 1), ('file', 2)]:
        with fixture() as (root, db, ids):
            ip = '203.0.113.8'
            key, limit = buckets(mode, ids[0], ip)[selected]
            seed(db, key, limit-1)
            tasks = [{'mode': mode, 'id': ids[i % 2] if selected == 1 and mode == 'file' else ids[0],
                      'ip': ip if mode == 'file' and selected == 1 else f'203.0.113.{8+i}'} for i in range(8)]
            results = race(root, tasks)
            wrong = 'login' if mode == 'admin' else 'password'
            winners = [i for i, r in enumerate(results) if r['reason'] == wrong]
            check(len(winners) == 1 and sum(r['reason'] == 'rate_limit' for r in results) == 7,
                  f'{mode} shared bucket {selected} constrains distinct identities atomically')
            expected = {key: limit-1}
            for i in winners:
                for charged, _ in buckets(mode, tasks[i]['id'], tasks[i]['ip']):
                    expected[charged] = expected.get(charged, 0)+1
            check(dict(db.execute('SELECT bucket,attempts FROM rate_limits')) == expected,
                  f'{mode} shared bucket {selected} rejects without partial charges')

    for mode in ['admin', 'file']:
        with fixture() as (root, db, ids):
            keys = buckets(mode, ids[0], '203.0.113.8')
            for key, _ in keys:
                seed(db, key, 3)
            task = {'mode': mode, 'root': str(root), 'id': ids[0], 'password': 'synthetic-correct-password'}
            check(command(task)['reason'] == 'ok', f'{mode} correct credentials still succeed')
            check(all(attempts(db, key) == 3 for key, _ in keys), f'{mode} success preserves previous failures')
            task['password'] = 'incorrect'
            check(command(task)['reason'] in ['login', 'password'], f'{mode} wrong credentials still reject')
            check(all(attempts(db, key) == 4 for key, _ in keys), f'{mode} failure is charged exactly once')
            db.execute('UPDATE rate_limits SET until_at=?', (int(time.time())-1,)); db.commit()
            check(command(task)['reason'] in ['login', 'password'], f'{mode} expired windows accept a new attempt')
            check(all(attempts(db, key) == 1 for key, _ in keys), f'{mode} expired windows start at one')

    # Failure before/during reservation and refund must never grant authentication.
    for mode in ['admin', 'file']:
        for operation in ['INSERT', 'UPDATE']:
            with fixture() as (root, db, ids):
                keys = buckets(mode, ids[0], '203.0.113.8')
                trigger_key = keys[-1][0]
                db.execute(f"CREATE TRIGGER auth_fault BEFORE {operation} ON rate_limits "
                           f"WHEN NEW.bucket='{trigger_key}' BEGIN SELECT RAISE(ABORT,'synthetic auth fault'); END")
                db.commit()
                result = command({'mode': mode, 'root': str(root), 'id': ids[0], 'password': 'synthetic-correct-password'})
                check(result['reason'] == 'unexpected' and result['type'] == 'PDOException',
                      f'{mode} {operation} database failure propagates')
                check(all(attempts(db, key) == (0 if operation == 'INSERT' else 1) for key, _ in keys),
                      f'{mode} {operation} failure rolls back atomically and retains reservations when required')
                check(db.execute('SELECT COUNT(*) FROM sessions').fetchone()[0] == 0 and
                      db.execute("SELECT COUNT(*) FROM audit WHERE action='login'").fetchone()[0] == 0 and
                      not list((root/'storage').glob('sess_*')),
                      f'{mode} {operation} failure grants no manager or download authorization')

    with fixture() as (root, db, ids):
        result = command({'mode': 'verify', 'root': str(root), 'keys': ['test:first', 'test:second'],
                          'limits': [8, 8], 'outcome': 'throw'})
        check(result['reason'] == 'unexpected', 'verification exceptions propagate')
        check(attempts(db, 'test:first') == 1 and attempts(db, 'test:second') == 1,
              'verification exceptions retain the attempted slot')

    with fixture() as (root, db, ids):
        proc, gate = blocked_verifier(root)
        try:
            check(attempts(db, 'test:first') == 1, 'in-flight verification already owns its slot')
            # This second connection must write while the verification callback is blocked.
            db.execute("UPDATE rate_limits SET attempts=4 WHERE bucket='test:first'"); db.commit()
            check(finish_verifier(proc, gate)['reason'] == 'ok', 'successful blocked verifier completes')
            check(attempts(db, 'test:first') == 3 and attempts(db, 'test:second') == 0,
                  'successful verification refunds only its slot and preserves concurrent failures')
        finally:
            if proc.poll() is None: proc.kill(); proc.wait()

    for replacement in [False, True]:
        with fixture() as (root, db, ids):
            proc, gate = blocked_verifier(root)
            try:
                db.execute("DELETE FROM rate_limits WHERE bucket='test:first'")
                if replacement:
                    db.execute('INSERT INTO rate_limits VALUES(?,?,?)', ('test:first', 4, int(time.time())+1800))
                db.commit()
                check(finish_verifier(proc, gate)['reason'] == 'ok', 'old-window verifier completes after maintenance')
                check(attempts(db, 'test:first') == (4 if replacement else 0),
                      'old-window refund cannot change replacement windows or recreate cleaned buckets')
            finally:
                if proc.poll() is None: proc.kill(); proc.wait()

    with fixture() as (root, db, ids):
        proc, gate = blocked_verifier(root)
        proc.kill(); proc.wait()
        check(attempts(db, 'test:first') == 1 and attempts(db, 'test:second') == 1,
              'terminated verification process cannot erase its attempted slots')
    print(f'{CHECKS-len(FAILURES)} authentication checks passed; {len(FAILURES)} failed')
    if FAILURES:
        raise AssertionError('Authentication rate checks failed')

if __name__ == '__main__':
    main()
