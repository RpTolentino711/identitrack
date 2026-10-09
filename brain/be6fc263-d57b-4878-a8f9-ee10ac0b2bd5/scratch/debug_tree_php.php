<?php
$modelData = json_decode(file_get_contents('admin/AI/softeng_2-master/server/modle/softeng_2_compiled_model.json'), true);

$features = [
    65 => 0.25350640925128637,
    303 => 0.3279262304013512,
    340 => 0.9100543323798196,
    500 => 2.0
];

$evalNode = function($node) use (&$evalNode, &$features) {
    if (count($node) === 1) {
        return (float)$node[0];
    }

    $featIdx = (int)$node[0];
    $splitCond = (float)$node[1];
    $yesNode = $node[2];
    $noNode = $node[3];
    $missingArg = $node[4];

    if (!array_key_exists($featIdx, $features)) {
        if (is_int($missingArg)) {
            $targetNode = ($missingArg === 1) ? $yesNode : $noNode;
        } else {
            $targetNode = $missingArg;
        }
        return $evalNode($targetNode);
    }

    $val = (float)$features[$featIdx];
    if ($val <= $splitCond) {
        return $evalNode($yesNode);
    } else {
        return $evalNode($noNode);
    }
};

$numClasses = (int)$modelData['num_classes'];
$labels = $modelData['labels'];
$trees = $modelData['trees'];
$logits = array_fill(0, $numClasses, 0.0);

foreach ($trees as $i => $t) {
    $classIdx = $i % $numClasses;
    $logits[$classIdx] += $evalNode($t);
}

$maxLogit = max($logits);
$expScores = [];
$sumExp = 0.0;
foreach ($logits as $l) {
    $e = exp($l - $maxLogit);
    $expScores[] = $e;
    $sumExp += $e;
}

foreach ($expScores as $idx => $e) {
    $p = $e / $sumExp;
    echo "Class {$idx} (" . $labels[$idx] . "): " . round($p * 100, 4) . "%\n";
}
