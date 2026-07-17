<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = getConnection();
    $st = $pdo->query("SELECT id,code,name,symbol FROM currencies WHERE status='active' ORDER BY is_base DESC,id");
    echo json_encode(['ok'=>true,'currencies'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
} catch(Exception $e) {
    echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
}
