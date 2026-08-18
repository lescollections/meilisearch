<?php
require_once(__DIR__ . '/_cadre.php');
require_once(__CA_LIB_DIR__.'/Plugins/SearchEngine/Meilisearch/Query.php');

$eng = moteur();
$schema = $eng->getSchema();

// On rejoue ce que fait SearchEngine : parseur + réécriture, en interceptant l'appel au moteur.
class Espion extends WLPlugSearchEngineMeilisearch {
    public static $vu = [];
    public function search(int $t, string $e, array $f, $rq) {
        $q = new Meilisearch\Query($this->getSchema(), $e, $rq);
        self::$vu[] = ['expression' => $e, 'groupes' => $q->getSearchGroups()];
        return parent::search($t, $e, $f, $rq);
    }
}

foreach (['ca_objects.idno:"CLE.1928.8"', 'CLE.1928.8'] as $expr) {
    $s = new ObjectSearch();
    // remplace le moteur par l'espion
    $ref = new ReflectionObject($s);
    while ($ref && !$ref->hasProperty('opo_engine')) { $ref = $ref->getParentClass(); }
    $p = $ref->getProperty('opo_engine'); $p->setAccessible(true);
    $p->setValue($s, new Espion());

    $res = $s->search($expr, ['no_cache' => true]);
    $ids = []; while ($res->nextHit()) { $ids[] = (int)$res->get('ca_objects.object_id'); }
    $v = array_pop(Espion::$vu);
    echo "expression CA : {$expr}\n";
    echo "  → passée au moteur : {$v['expression']}\n";
    foreach ($v['groupes'] as $g) {
        echo "  → groupe [{$g['op']}] q=".json_encode($g['params']['q'], JSON_UNESCAPED_UNICODE)
           . " sur ".json_encode($g['params']['attributesToSearchOn'] ?? '*')
           . " stratégie=".($g['params']['matchingStrategy'] ?? '-')."\n";
    }
    echo "  → résultats : ".json_encode($ids)."\n\n";
}
