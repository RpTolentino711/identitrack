<?php
declare(strict_types=1);

function runNativePhpXgbPrediction(array $payload): ?array
{
    $modelFile = __DIR__ . '/../../../admin/AI/softeng_2-master/server/modle/softeng_2_compiled_model.json';
    if (!file_exists($modelFile)) {
        return null;
    }

    static $modelData = null;
    if ($modelData === null) {
        $jsonStr = file_get_contents($modelFile);
        $modelData = json_decode($jsonStr, true);
    }

    if (!is_array($modelData) || empty($modelData['trees'])) {
        return null;
    }

    $scenario = (string)($payload['description'] ?? '');
    $category = (string)($payload['category'] ?? '');
    $violation = (string)($payload['violation'] ?? '');
    $numOffenseStr = (string)($payload['number_of_offense'] ?? '1st offense');

    if (empty($scenario)) {
        $scenario = !empty($violation) ? $violation : 'Disciplinary Violation';
    }

    $clean = function(string $str): string {
        $str = strtolower($str);
        $str = preg_replace('/[^a-z0-9\s]/', '', $str);
        return trim((string)preg_replace('/\s+/', ' ', $str));
    };

    $combinedText = trim($clean($scenario) . ' ' . $clean($category) . ' ' . $clean($violation));
    preg_match_all('/\b[a-z0-9_]{2,}\b/', $combinedText, $mMatches);
    $words = $mMatches[0] ?? [];

    // Build unigrams and bigrams
    $tokens = [];
    $count = count($words);
    for ($i = 0; $i < $count; $i++) {
        $tokens[] = $words[$i];
        if ($i + 1 < $count) {
            $tokens[] = $words[$i] . ' ' . $words[$i+1];
        }
    }

    $vocab = $modelData['vocab'];
    $idf = $modelData['idf'];

    // 1. Term frequencies
    $termCounts = [];
    foreach ($tokens as $tok) {
        if (isset($vocab[$tok])) {
            $idx = (int)$vocab[$tok];
            $termCounts[$idx] = ($termCounts[$idx] ?? 0) + 1;
        }
    }

    // 2. TF-IDF & L2 Normalization
    $features = [];
    $sumSq = 0.0;
    foreach ($termCounts as $idx => $tf) {
        $val = (float)$tf * (float)$idf[$idx];
        $features[$idx] = $val;
        $sumSq += $val * $val;
    }

    $norm = ($sumSq > 0) ? sqrt($sumSq) : 1.0;
    if ($norm > 0) {
        foreach ($features as $idx => $val) {
            $features[$idx] = $val / $norm;
        }
    }

    // Parse number of offense for feature index 500
    $numOffense = 1.0;
    if (preg_match('/(\d+)/', $numOffenseStr, $m)) {
        $numOffense = (float)$m[1];
    }
    $features[500] = $numOffense;

    // 3. Traversal of 13,000 XGBoost decision trees
    $numClasses = (int)$modelData['num_classes'];
    $labels = $modelData['labels'];
    $trees = $modelData['trees'];
    $logits = array_fill(0, $numClasses, 0.0);

    foreach ($trees as $t) {
        $cIdx = (int)$t['class'];
        $lefts = $t['lefts'];
        $rights = $t['rights'];
        $splits = $t['splits'];
        $conds = $t['conds'];
        $weights = $t['weights'];
        $defaults = $t['defaults'];

        $curr = 0;
        while ($lefts[$curr] !== -1) {
            $fIdx = (int)$splits[$curr];
            if (!isset($features[$fIdx])) {
                $curr = ($defaults[$curr] === 1) ? $lefts[$curr] : $rights[$curr];
            } else {
                $val = (float)$features[$fIdx];
                if ($val <= (float)$conds[$curr]) {
                    $curr = $lefts[$curr];
                } else {
                    $curr = $rights[$curr];
                }
            }
        }
        $logits[$cIdx] += (float)$weights[$curr];
    }

    // 4. Softmax
    $maxLogit = max($logits);
    $expScores = [];
    $sumExp = 0.0;
    foreach ($logits as $l) {
        $e = exp($l - $maxLogit);
        $expScores[] = $e;
        $sumExp += $e;
    }

    $bestIdx = 0;
    $bestProb = 0.0;
    foreach ($expScores as $idx => $e) {
        $p = $e / $sumExp;
        if ($p > $bestProb) {
            $bestProb = $p;
            $bestIdx = $idx;
        }
    }

    $predictedSanction = $labels[$bestIdx] ?? 'Category 1';
    $likelihoodPercentage = round($bestProb * 100.0, 2);

    $determineSeverity = function(float $lk): string {
        if ($lk >= 75) return "Critical";
        if ($lk >= 50) return "High";
        if ($lk >= 25) return "Medium";
        return "Low";
    };

    return [
        'category' => $category ?: 'Uncategorized',
        'sanction' => $predictedSanction,
        'sanction_confidence' => $likelihoodPercentage,
        'severity' => $determineSeverity($likelihoodPercentage),
        'likelihood_percentage' => $likelihoodPercentage,
        'confidence_score' => $likelihoodPercentage,
        'model_status' => 'active_native_php'
    ];
}
