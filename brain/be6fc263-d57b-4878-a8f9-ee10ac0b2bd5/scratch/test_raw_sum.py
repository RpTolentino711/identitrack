import json
import joblib
import numpy as np
import scipy.sparse as sp
import xgboost as xgb

m = xgb.Booster()
m.load_model('admin/AI/softeng_2-master/server/modle/sanction_xgb_model.json')

row = [0]*501
row[65] = 0.25350640925128637
row[303] = 0.3279262304013512
row[340] = 0.9100543323798196
row[500] = 2.0

X = sp.csr_matrix([row])
dmat = xgb.DMatrix(X)
target_probs = m.predict(dmat)[0]

raw_trees = [json.loads(t) for t in m.get_dump(dump_format='json')]

feat_dict = {65: 0.25350640925128637, 303: 0.3279262304013512, 340: 0.9100543323798196, 500: 2.0}

def eval_single_tree(node, feat_map):
    if 'leaf' in node:
        return float(node['leaf'])
    feat_idx = int(node['split'].replace('f', ''))
    missing_id = node['missing']
    yes_id = node['yes']
    no_id = node['no']
    children_map = {c['nodeid']: c for c in node['children']}
    
    if feat_idx not in feat_map:
        return eval_single_tree(children_map[missing_id], feat_map)
    val = feat_map[feat_idx]
    if val <= float(node['split_condition']):
        return eval_single_tree(children_map[yes_id], feat_map)
    else:
        return eval_single_tree(children_map[no_id], feat_map)

# Method 1: i % 13
logits_1 = [0.0]*13
for i, t in enumerate(raw_trees):
    logits_1[i % 13] += eval_single_tree(t, feat_dict)
exp_1 = np.exp(logits_1 - np.max(logits_1))
probs_1 = exp_1 / np.sum(exp_1)

# Method 2: i // 1000
logits_2 = [0.0]*13
for i, t in enumerate(raw_trees):
    logits_2[i // 1000] += eval_single_tree(t, feat_dict)
exp_2 = np.exp(logits_2 - np.max(logits_2))
probs_2 = exp_2 / np.sum(exp_2)

print('Target XGBoost probs: ', np.round(target_probs * 100, 4))
print('Method 1 (i % 13):   ', np.round(probs_1 * 100, 4))
print('Method 2 (i // 1000):', np.round(probs_2 * 100, 4))
