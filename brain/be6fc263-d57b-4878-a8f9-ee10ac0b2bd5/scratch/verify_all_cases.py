import subprocess
import json
import os

cases = [
    {"description": "cheating during major exam", "category": "Major Offenses", "violation": "Cheating", "number_of_offense": "1st offense"},
    {"description": "brawl physical fight on campus", "category": "Major Offenses", "violation": "Brawl / Physical Assault", "number_of_offense": "2nd offense"},
    {"description": "littering trash in hallway", "category": "Minor Offenses", "violation": "Littering", "number_of_offense": "1st offense"},
    {"description": "possession of deadly weapon firearm knife", "category": "Major Offenses", "violation": "Possession of Deadly Weapon", "number_of_offense": "1st offense"},
    {"description": "unauthorized smoking inside premises", "category": "Minor Offenses", "violation": "Smoking / Vaping", "number_of_offense": "3rd offense"}
]

for i, c in enumerate(cases):
    # Run Python CLI
    p_py = subprocess.run(
        ['admin/AI/softeng_2-master/server/venv/Scripts/python.exe', 'admin/AI/softeng_2-master/server/predict_cli.py', json.dumps(c)],
        capture_output=True, text=True
    )
    res_py = json.loads(p_py.stdout.strip())
    
    c_json = json.dumps(json.dumps(c))
    tmp_php = f'''<?php
    require_once __DIR__ . "/test_native_php_xgb.php";
    $payload = json_decode({c_json}, true);
    echo json_encode(runNativePhpXgbPrediction($payload));
    '''
    with open('brain/be6fc263-d57b-4878-a8f9-ee10ac0b2bd5/scratch/run_temp.php', 'w') as f:
        f.write(tmp_php)
        
    p_php = subprocess.run(['php', 'brain/be6fc263-d57b-4878-a8f9-ee10ac0b2bd5/scratch/run_temp.php'], capture_output=True, text=True)
    res_php = json.loads(p_php.stdout.strip())
    
    match_sanct = (res_py.get('sanction') == res_php.get('sanction'))
    match_conf = abs(float(res_py.get('sanction_confidence', 0)) - float(res_php.get('sanction_confidence', 0))) < 0.1
    
    print(f"Case {i+1}: {c['violation']} ({c['number_of_offense']})")
    print(f"  Py  Sanction: {res_py.get('sanction')} ({res_py.get('sanction_confidence')}%)")
    print(f"  PHP Sanction: {res_php.get('sanction')} ({res_php.get('sanction_confidence')}%)")
    print(f"  MATCH: {match_sanct and match_conf}\n")
