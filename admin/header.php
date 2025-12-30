<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>File Upload Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background-color: #f8f9fa; }
    .nav-link.active { font-weight: bold; }
    .nav-pills .nav-link { color: #fff; }
    .nav-pills .nav-link.active { background-color: #0a58ca; }
    .navbar-custom { background-color: #0d6efd; }
    .navbar-custom .nav-link, .navbar-custom .navbar-brand { color: #fff; }
    .logout-btn { position: absolute; right: 10px; top: 10px; }
  </style>
</head>
<body>
