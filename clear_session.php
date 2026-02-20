<?php
session_start();
unset($_SESSION['last_job']);
echo json_encode(['success' => true]);
?>
