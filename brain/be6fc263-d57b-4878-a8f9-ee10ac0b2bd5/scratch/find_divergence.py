import json
import joblib
import re
import math
import numpy as np
import scipy.sparse as sp
import xgboost as xgb

with open('admin/AI/softeng_2-master/server/modle/softeng_2_compiled_model.json', 'r', encoding='utf-8') as f:
    model_data = json.load(f)

trees = model_data['trees']
num_classes = model_data['num_classes']

m = xgb.Booster()
m.load_model('admin/AI/softeng_2-master/server/modle/sanction_xgb_model.json')
tfidf = joblib.load('admin/AI/softeng_2-master/server/modle/tfidf_vectorizer.pkl')

text = 'brawl physical fight on campus major offenses brawl physical assault'
X_text = tfidf.transform([text])
X_num = sp.csr_matrix([[2.0]])
X = sp.hstack([X_text, X_num]).tocsr()
dmat = xgb.DMatrix(X)

feat_dict = {int(i): float(d) for i, d in zip(X_text.indices, X_text.data)}
feat_dict[500] = 2.0

raw_trees = [json.loads(t) for t in m.get_dump(dump_format='json')]

def eval_py_tree(node, feat_map):
    if 'leaf' in node:
        return float(node['leaf'])
    feat_idx = int(node['split'].replace('f', ''))
    missing_id = node['missing']
    yes_id = node['yes']
    no_id = node['no']
    children_map = {c['nodeid']: c for c in node['children']}
    
    if feat_idx not in feat_map:
        return eval_py_tree(children_map[missing_id], feat_map)
    val = feat_map[feat_idx]
    if val <= float(node['split_condition']):
        return eval_py_tree(children_map[yes_id], feat_map)
    else:
        return eval_py_tree(children_map[no_id], feat_map)

def eval_json_tree(node, feat_map):
    if len(node) == 1:
        return float(node[0])
    feat_idx = int(node[0])
    split_cond = float(node[1])
    yes_node = node[2]
    no_node = node[3]
    missing_flag = node[4]
    
    if feat_idx not in feat_map:
        target = yes_node if missing_flag == 1 else no_node
        return eval_json_tree(target, feat_map)
    
    val = feat_map[feat_idx]
    if val <= split_cond:
        return eval_json_tree(yes_node, feat_map)
    else:
        return eval_json_tree(no_node, feat_map)

diff_count = 0
for i in range(len(raw_trees)):
    v1 = eval_py_tree(raw_trees[i], feat_dict)
    v2 = eval_json_tree(trees[i], feat_dict)
    if abs(v1 - v2) > 1e-6:
        print(f"Tree {i} (Class {i % 13}): Py={v1}, JSON={v2}")
        diff_count += 1
        if diff_count > 10:
            break

if diff_count == 0:
    print("ALL 13,000 TREES MATCH PERFECTLY IN PYTHON!")
