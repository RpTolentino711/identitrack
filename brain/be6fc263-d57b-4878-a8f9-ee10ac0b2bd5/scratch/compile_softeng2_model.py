import json
import joblib
import re
import os
import numpy as np
import xgboost as xgb

base_dir = os.path.dirname(os.path.abspath(__file__))
server_dir = os.path.abspath('admin/AI/softeng_2-master/server')
model_dir = os.path.join(server_dir, 'modle')

xgb_file = os.path.join(model_dir, 'sanction_xgb_model.json')
le_file = os.path.join(model_dir, 'label_encoder.pkl')
tfidf_file = os.path.join(model_dir, 'tfidf_vectorizer.pkl')

print("Loading model files...")
m = xgb.Booster()
m.load_model(xgb_file)

le = joblib.load(le_file)
labels = [str(c) for c in le.classes_]

tfidf = joblib.load(tfidf_file)
vocab = {str(k): int(v) for k, v in tfidf.vocabulary_.items()}
idf = tfidf.idf_.tolist()

# Get raw dump of trees
raw_trees_json = [json.loads(t) for t in m.get_dump(dump_format='json')]

# Compact representation of trees for PHP evaluator
# Node format:
# Leaf node: [leaf_value] (length 1)
# Internal node: [feature_idx, split_condition, yes_child_idx, no_child_idx] (length 4)

def compact_tree(node):
    if 'leaf' in node:
        return [round(float(node['leaf']), 8)]
    
    # Feature name is like 'f202' or 'f500'
    feat_str = node['split']
    feat_idx = int(feat_str.replace('f', ''))
    split_cond = round(float(node['split_condition']), 8)
    
    children = node['children']
    left_compact = compact_tree(children[0])
    right_compact = compact_tree(children[1])
    
    return [feat_idx, split_cond, left_compact, right_compact]

print(f"Compacting {len(raw_trees_json)} decision trees...")
compact_trees = [compact_tree(t) for t in raw_trees_json]

compiled_data = {
    "num_classes": len(labels),
    "labels": labels,
    "vocab": vocab,
    "idf": idf,
    "base_score": 0.5,
    "trees": compact_trees
}

out_file = os.path.join(model_dir, 'softeng_2_compiled_model.json')
with open(out_file, 'w', encoding='utf-8') as f:
    json.dump(compiled_data, f, separators=(',', ':'))

file_size_mb = os.path.getsize(out_file) / (1024 * 1024)
print(f"SUCCESS! Compiled model written to {out_file} ({file_size_mb:.2f} MB)")
