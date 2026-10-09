import subprocess
import json

c = {"description": "brawl physical fight on campus", "category": "Major Offenses", "violation": "Brawl / Physical Assault", "number_of_offense": "2nd offense"}

p_py = subprocess.run(
    ['admin/AI/softeng_2-master/server/venv/Scripts/python.exe', 'admin/AI/softeng_2-master/server/predict_cli.py', json.dumps(c)],
    capture_output=True, text=True
)
print("PYTHON CLI RESULT:")
print(p_py.stdout)

c_json = json.dumps(json.dumps(c))
tmp_php = f'''<?php
require_once "brain/be6fc263-d57b-4878-a8f9-ee10ac0b2bd5/scratch/test_native_php_xgb.php";
$payload = json_decode({c_json}, true);
echo json_encode(runNativePhpXgbPrediction($payload), JSON_PRETTY_PRINT);
'''
with open('brain/be6fc263-d57b-4878-a8f9-ee10ac0b2bd5/scratch/run_temp.php', 'w') as f:
    f.write(tmp_php)

p_php = subprocess.run(['php', 'brain/be6fc263-d57b-4878-a8f9-ee10ac0b2bd5/scratch/run_temp.php'], capture_output=True, text=True)
print("PHP NATIVE RESULT:")
print(p_php.stdout)
