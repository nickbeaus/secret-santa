<?php require_once __DIR__ . '/../includes/bootstrap.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secret Santa Exchange</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <!-- Google Fonts - Mountains of Christmas -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Mountains+of+Christmas:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #333;
        }
        .navbar-brand, .festive-title {
            font-family: 'Mountains of Christmas', cursive;
            font-weight: 700;
        }
        .hero {
            background: linear-gradient(315deg, #d9534f 0%, #c12a2a 60%, #1e5631 100%);
            background-size: cover;
            background-position: center;
            color: white;
            padding: 6rem 0;
            text-align: center;
            border-radius: 0 0 1rem 1rem;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .hero h1 {
            font-size: 4.5rem;
        }
        .hero p {
            font-size: 1.5rem;
            max-width: 600px;
            margin: 1rem auto 0;
        }
        .section {
            padding: 4rem 0;
        }
        .card {
            border: none;
            border-radius: 1rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .card-title {
            color: #d9534f; /* A festive red */
        }
        .footer {
            background-color: #343a40;
            color: white;
            padding: 2rem 0;
            text-align: center;
        }
        .icon {
            font-size: 3rem;
            color: #d9534f;
        }
    </style>
</head>
<body>

<!-- Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
    <div class="container">
        <a class="navbar-brand fs-2" href="#">Secret Santa</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a href="admin_login.php" class="btn btn-outline-light">Admin Login</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Hero Section -->
<header class="hero">
    <div class="container">
        <h1 class="display-3 festive-title">Welcome to Secret Santa!</h1>
        <p class="lead">Get ready for a fun and festive gift exchange with friends, family, or colleagues.</p>
    </div>
</header>

<!-- Main Content -->
<main class="container">
    <!-- What is this? Section -->
    <section class="section text-center">
        <h2 class="mb-5">What is a Secret Santa?</h2>
        <div class="row g-4 justify-content-center">
            <div class="col-md-8">
                <div class="card p-4 h-100">
                    <div class="card-body">
                        <div class="icon mb-3">🎁</div>
                        <h4 class="card-title">A Tradition of Surprise!</h4>
                        <p class="card-text">
                            Secret Santa is a wonderful holiday tradition where members of a group are randomly assigned a person to whom they give a gift. The identity of the gift giver remains a secret until the very end. It's all about the surprise and the joy of giving!
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How it works Section -->
    <section class="section bg-light rounded-3">
        <div class="text-center mb-5">
            <h2>How Our Exchange Works</h2>
            <p class="text-muted">Simple, secure, and managed for you.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="card text-center p-4 h-100">
                    <div class="card-body">
                        <div class="icon mb-3">👤</div>
                        <h5 class="card-title">1. Admin Creates the Group</h5>
                        <p class="card-text">An administrator will set up the gift exchange and add all the participants to the group. You don't need to sign up or create an account.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center p-4 h-100">
                    <div class="card-body">
                        <div class="icon mb-3">📧</div>
                        <h5 class="card-title">2. Receive Your Assignment</h5>
                        <p class="card-text">The admin will send you a unique, private link. Follow that link to magically reveal the name of the person you'll be getting a gift for. Keep it secret!</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center p-4 h-100">
                    <div class="card-body">
                        <div class="icon mb-3">🎉</div>
                        <h5 class="card-title">3. Exchange Gifts!</h5>
                        <p class="card-text">Find the perfect gift, and on the designated day, exchange your presents! Enjoy the fun of guessing who your Secret Santa was and seeing the joy your gift brings.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Admin Note Section -->
    <section class="section text-center">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="alert alert-info" role="alert" style="border-radius: 1rem;">
                    <h4 class="alert-heading">Please Note!</h4>
                    <p>This is a closed group. All users are added and managed by an event administrator to ensure everything is fair and secure. If you believe you should be a part of this exchange, please contact your group's organizer.</p>
                </div>
            </div>
        </div>
    </section>

</main>

<!-- Footer -->
<footer class="footer mt-5">
    <div class="container">
        <p>&copy; <?php echo date('Y'); ?> Secret Santa Exchange. Happy Holidays!</p>
    </div>
</footer>

<!-- Bootstrap JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
</body>
</html>
