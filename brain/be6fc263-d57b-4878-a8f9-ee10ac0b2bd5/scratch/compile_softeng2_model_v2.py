import json
import joblib
import re
import os
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
idf = [round(float(val), 8) for val in tfidf.idf_]

raw_trees_json = [json.loads(t) for t in m.get_dump(dump_format='json')]

# Compact tree encoder:
# Leaf node: [leaf_value] (len 1)
# Split node: [feature_idx, split_condition, yes_node, no_node, missing_node]
def build_compact_node(node):
    if 'leaf' in node:
        return [round(float(node['leaf']), 8)]
    
    feat_idx = int(node['split'].replace('f', ''))
    split_cond = round(float(node['split_condition']), 8)
    
    yes_id = node['yes']
    no_id = node['no']
    missing_id = node['missing']
    
    children_map = {c['nodeid']: c for c in node['children']}
    
    yes_compact = build_compact_node(children_map[yes_id])
    no_compact = build_compact_node(children_map[no_id])
    
    # If missing child is same instance as yes or no child, optimization:
    missing_flag = 1 if missing_id == yes_id else (2 if missing_id == no_id else 0)
    
    if missing_flag != 0:
        return [feat_idx, split_cond, yes_compact, no_compact, missing_flag]
    else:
        missing_compact = build_compact_node(children_map[missing_id])
        return [feat_idx, split_cond, yes_compact, no_compact, missing_compact]

print(f"Compacting {len(raw_trees_json)} decision trees...")
compact_trees = [build_compact_node(t) for t in raw_trees_json]

compiled_data = {
    "num_classes": len(labels),
    "labels": labels,
    "vocab": vocab,
    "idf": idf,
    "trees": compact_trees
}

out_file = os.path.join(model_dir, 'softeng_2_compiled_model.json')
with open(out_file, 'w', encoding='utf-8') as f:
    json.dump(compiled_data, f, separators=(',', ':'))

file_size_mb = os.path.getsize(out_file) / (1024 * 1024)
print(f"SUCCESS! Compiled model written to {out_file} ({file_size_mb:.2f} MB)")
