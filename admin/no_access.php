<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Access denied</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
  <div class="container py-5">
    <div class="alert alert-danger shadow-sm">
      <h4 class="alert-heading">Access denied</h4>
      <p>You don’t have permission to access this page. If you believe this is a mistake, please contact a master admin.</p>
      <hr>
      <a href="dashboard.php" class="btn btn-primary">Go to Dashboard</a>
      <a href="logout.php" class="btn btn-outline-secondary ms-2">Logout</a>
    </div>
  </div>
</body>
</html>
