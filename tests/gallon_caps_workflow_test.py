"""Exercise actual report SQL and rendered checkout JavaScript without live customer data."""
import json
import os
from pathlib import Path
import sqlite3
import subprocess

ROOT = Path(__file__).resolve().parents[1]
os.chdir(ROOT)
PHP = os.environ.get('PHP_BINARY', 'C:/xampp/php/php.exe')

def php(code):
    return subprocess.check_output([PHP], input=code.encode('utf-8')).decode('utf-8')

sql = php("<?php require 'config/gallon_caps.php'; echo cap_summary_sql(\" AND created_at >= '2026-10-01 00:00:00' AND created_at < '2026-10-04 00:00:00'\");")
db = sqlite3.connect(':memory:')
db.execute('CREATE TABLE transactions (transaction_id TEXT, status TEXT, delivery_status TEXT, payment_status TEXT, created_at TEXT, cap_quantity INTEGER DEFAULT 0, cap_subtotal NUMERIC DEFAULT 0)')
rows = [
 ('A','pending','pending','pending','2026-10-01 00:00:00',2,10),
 ('B','approved','delivered','paid','2026-10-02 12:00:00',3,15.75),
 ('C','approved','delivered','pending','2026-10-02 13:00:00',4,20),
 ('D','cancelled','delivered','paid','2026-10-02 14:00:00',10,50),
 ('E','approved','cancelled','paid','2026-10-02 15:00:00',10,50),
 ('F','denied','pending','paid','2026-10-02 16:00:00',10,50),
 ('G','completed','completed','paid','2026-10-03 23:59:59',5,25),
 ('H','approved','delivered','paid','2026-10-04 00:00:00',6,30),
 ('RWD-1','approved','delivered','paid','2026-10-02 12:00:00',100,500),
 ('DEMO-1','approved','delivered','paid','2026-10-02 12:00:00',100,500),
 ('OLD','approved','delivered','paid','2026-10-02 12:00:00',0,0),
]
db.executemany('INSERT INTO transactions VALUES (?,?,?,?,?,?,?)', rows)
assert db.execute(sql).fetchone() == (14,12,40.75)
# Payment duplicates have no influence because the production summary reads orders only.
db.execute('CREATE TABLE payments (transaction_id TEXT)')
db.executemany('INSERT INTO payments VALUES (?)', [('B',),('B',),('B',)])
assert db.execute(sql).fetchone() == (14,12,40.75)
assert db.execute(sql.replace('2026-10-01','2026-10-02').replace('2026-10-04','2026-10-03')).fetchone() == (7,7,15.75)
assert db.execute(sql.replace('2026-10-01','2026-11-01').replace('2026-10-04','2026-11-04')).fetchone() == (0,0,0)
# Execute the actual admin filter-building block for combinations.
admin = (ROOT/'admin/transactions.php').read_text(encoding='utf-8')
filters = admin[admin.index('// Build WHERE clauses'):admin.index('// Get filtered transactions')]
for status, caps, expected in [('approved','yes',5),('approved','no',1),('pending','yes',1),('delivered','no',1)]:
    code = "<?php $date_clauses=[\"created_at >= '2026-10-01 00:00:00'\", \"created_at < '2026-10-04 00:00:00'\"]; $filter_method='all'; $filter_status="+json.dumps(status)+"; $filter_caps="+json.dumps(caps)+"; "+filters+" echo $where_sql;"
    where = php(code)
    assert db.execute('SELECT COUNT(*) FROM transactions t WHERE 1=1'+where).fetchone()[0] == expected, (status,caps)
print('PASS report SQL: date boundaries, exclusions, completion, paid sales, duplicates, empty ranges, combined admin filters (SQLite fixtures)')

checkout = (ROOT/'user/checkout.php').read_text(encoding='utf-8')
body = checkout[checkout.index('function updateDisplay()'):checkout.index('document.addEventListener("DOMContentLoaded", updateDisplay);')]
for mode in ['existing','new']:
    rendered = php("<?php $container_status="+json.dumps(mode)+"; $container_size='5gal-round'; $container_price_map=['5gal-round'=>160]; $free_delivery_reward=false; $cap_price=5.25; ?>"+body)
    harness = '''
const assert = require('node:assert/strict');
const elements = new Map();
const document = {getElementById(id) {if (!elements.has(id)) elements.set(id,{_value:'0',get value(){return this._value;},set value(v){this._value=String(v);},style:{},checked:false,hidden:false,setAttribute(){}}); return elements.get(id);}};
let currentQuantity=3, availableStock=100, isDelivery=true;
function waterOrderTotal(q) {return q<=4 ? 80 : q*15;}
'''+rendered+'''
const add = document.getElementById('addGallonCap'), qty = document.getElementById('capQuantity');
updateDisplay();
assert.equal(qty.value,'0'); assert.equal(qty.disabled,true); assert.equal(document.getElementById('capFields').hidden,true);
add.checked=true; updateDisplay(); qty.value='3';updateDisplay();
assert.equal(qty.required,true);assert.equal(document.getElementById('capSubtotal').textContent,'PHP 15.75');
let water=MODE==='new'?480:80;
assert.equal(document.getElementById('hiddenAmount').value,(water+30+15.75).toFixed(2));
qty.value='7';updateDisplay();assert.equal(document.getElementById('hiddenAmount').value,(water+30+36.75).toFixed(2));
currentQuantity=5;updateDisplay();water=MODE==='new'?800:75;
assert.equal(document.getElementById('hiddenAmount').value,(water+50+36.75).toFixed(2));
add.checked=false;updateDisplay();assert.equal(qty.value,'0');assert.equal(qty.disabled,true);assert.equal(document.getElementById('capSubtotal').textContent,'PHP 0.00');
assert.equal(document.getElementById('hiddenAmount').value,(water+50).toFixed(2));
isDelivery=false;updateDisplay();assert.equal(document.getElementById('hiddenAmount').value,water.toFixed(2));
console.log('PASS rendered checkout calculations: '+MODE);
'''
    harness = 'const MODE='+json.dumps(mode)+';\n'+harness
    subprocess.run(['node','-e',harness], check=True)

# Ownership helper: no real session files or customer accounts are used.
for requested, allowed in [('CUSTOMER-A', True), ('CUSTOMER-B', False)]:
    code = """<?php
    session_set_save_handler(new class implements SessionHandlerInterface {
        public function open($path,$name):bool{return true;}
        public function close():bool{return true;}
        public function read($id):string{return '';}
        public function write($id,$data):bool{return true;}
        public function destroy($id):bool{return true;}
        public function gc($max):int|false{return 0;}
    },true);
    session_start(); $_SESSION['customer_user_id']='CUSTOMER-A';
    register_shutdown_function(function(){echo '|HTTP='.(http_response_code() ?: 200);});
    require 'config/customer_order_access.php';
    require_customer_order_access(REQUESTED); echo 'ALLOWED';
    """.replace('REQUESTED', json.dumps(requested))
    result = php(code)
    assert ('ALLOWED' in result) == allowed
    assert ('HTTP=200' if allowed else 'HTTP=403') in result
print('PASS customer ownership: own account allowed, another account rejected')
