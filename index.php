<?php
// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "rural_banking";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch latest news from database
$news_sql = "SELECT * FROM news ORDER BY created_at DESC LIMIT 5";
$news_result = $conn->query($news_sql);

// Fetch banners from database
$banner_sql = "SELECT * FROM banners WHERE active = 1 ORDER BY display_order";
$banner_result = $conn->query($banner_sql);

// Fetch services from database
$services_sql = "SELECT * FROM services WHERE active = 1 ORDER BY display_order";
$services_result = $conn->query($services_sql);

// Fetch security stats from database
$stats_sql = "SELECT * FROM security_stats WHERE id = 1";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Fetch testimonials from database
$testimonials_sql = "SELECT * FROM testimonials WHERE approved = 1 ORDER BY created_at DESC LIMIT 4";
$testimonials_result = $conn->query($testimonials_sql);

// Fetch transaction types from database
$transaction_types_sql = "SELECT * FROM transaction_types WHERE active = 1 ORDER BY display_order";
$transaction_types_result = $conn->query($transaction_types_sql);

// Fetch user count (simulated)
$user_count_sql = "SELECT COUNT(*) as count FROM users";
$user_count_result = $conn->query($user_count_sql);
$user_count = $user_count_result->fetch_assoc();

// Current date and time
$current_date = date('l, F j, Y');
$current_time = date('h:i A');

// Visitor count (simulated - in real implementation, use sessions/cookies)
$visitor_count = rand(1500, 2500);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Rural Banking Framework</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1a5276;
            --secondary-color: #28a745;
            --accent-color: #ffc107;
            --light-bg: #f8f9fa;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
        }
        
        /* Header Styles */
        .main-header {
            background: linear-gradient(135deg, var(--primary-color), #2e86c1);
            color: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .logo {
            font-weight: 700;
            font-size: 24px;
            display: flex;
            align-items: center;
        }
        
        .logo i {
            margin-right: 10px;
            font-size: 28px;
        }
        
        /* Top Bar Styles */
        .top-bar {
            background-color: #2c3e50;
            color: white;
            padding: 8px 0;
            font-size: 14px;
        }
        
        .top-bar a {
            color: var(--accent-color);
            text-decoration: none;
        }
        
        /* Navigation Styles */
        .main-navbar {
            background-color: rgba(26, 82, 118, 0.6);
            box-shadow: 0 2px 10px rgba(26, 82, 118, 0.10);
            border-bottom: 1px solid rgba(255,255,255,0.05);
            padding: 0;
            min-height: 50px;
            backdrop-filter: blur(10px);
            position: relative;
            z-index: 1050;
        }
        
        .navbar-nav .nav-link {
            color: rgba(255,255,255,0.9) !important;
            font-weight: 500;
            padding: 12px 15px !important;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .navbar-nav .nav-link:hover {
            color: white !important;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
        }
        
        .dropdown-menu {
            background-color: white;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
            z-index: 2000;
        }
        
        .dropdown-item {
            padding: 10px 15px;
            font-size: 14px;
            color: #333;
            transition: all 0.2s;
        }
        
        .dropdown-item:hover {
            background-color: #f8f9fa;
            color: var(--primary-color);
        }
        
        .dropdown-item i {
            width: 20px;
            margin-right: 8px;
            color: var(--primary-color);
        }
        
        .language-selector {
            background-color: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            margin-left: 15px;
        }
        
        .notification-banner {
            background: linear-gradient(135deg, #ffc107, #ff9800);
            color: #212529;
            padding: 12px 0;
            text-align: center;
            font-weight: 500;
        }
        
        .notification-banner i {
            margin-right: 10px;
        }
        
        /* Hero Section */
        .hero-section {
            background: url('https://images.unsplash.com/photo-1560518883-ce09059eeffa?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80') no-repeat center center;
            background-size: cover;
            padding: 80px 0;
            color: white;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.7);
            position: relative;
        }
        
        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
        }
        
        .hero-content {
            position: relative;
            z-index: 1;
        }
        
        .section-title {
            color: var(--primary-color);
            border-bottom: 2px solid var(--accent-color);
            padding-bottom: 10px;
            margin-bottom: 30px;
            display: inline-block;
        }
        
        .feature-card {
            border-radius: 10px;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
            height: 100%;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
        }
        
        .feature-icon {
            font-size: 40px;
            color: var(--primary-color);
            margin-bottom: 20px;
        }
        
        .security-badge {
            background-color: var(--secondary-color);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 15px;
        }
        
        .partition-section {
            padding: 80px 0;
        }
        
        .partition-1 {
            background-color: white;
        }
        
        .partition-2 {
            background-color: var(--light-bg);
        }
        
        .partition-card {
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: all 0.3s;
            height: 100%;
            border: none;
        }
        
        .partition-card:hover {
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }
        
        .partition-card-header {
            background: linear-gradient(135deg, var(--primary-color), #2e86c1);
            color: white;
            padding: 20px;
            text-align: center;
        }
        
        .partition-card-body {
            padding: 30px;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-color), #2e86c1);
            border: none;
            padding: 12px 30px;
            font-weight: 600;
            border-radius: 30px;
            transition: all 0.3s;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 7px 15px rgba(0,0,0,0.2);
        }
        
        .btn-success-custom {
            background: linear-gradient(135deg, var(--secondary-color), #52c41a);
            border: none;
            padding: 12px 30px;
            font-weight: 600;
            border-radius: 30px;
            transition: all 0.3s;
        }
        
        .btn-success-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 7px 15px rgba(0,0,0,0.2);
        }
        
        .news-section {
            background-color: white;
            padding: 80px 0;
        }
        
        .news-card {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: all 0.3s;
            height: 100%;
            margin-bottom: 30px;
        }
        
        .news-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        
        .news-date {
            color: #6c757d;
            font-size: 14px;
        }
        
        .banner-carousel {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .footer {
            background-color: #2c3e50;
            color: white;
            padding: 60px 0 30px;
        }
        
        .footer h5 {
            color: var(--accent-color);
            margin-bottom: 20px;
        }
        
        .footer-links a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            display: block;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        
        .footer-links a:hover {
            color: white;
            padding-left: 5px;
        }
        
        .copyright {
            border-top: 1px solid rgba(255,255,255,0.1);
            padding-top: 20px;
            margin-top: 40px;
            text-align: center;
            color: rgba(255,255,255,0.6);
        }
        
        .security-features {
            background-color: #e8f4fc;
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
        }
        
        .feature-list {
            list-style-type: none;
            padding-left: 0;
        }
        
        .feature-list li {
            margin-bottom: 10px;
            padding-left: 25px;
            position: relative;
        }
        
        .feature-list li:before {
            content: "✓";
            color: var(--secondary-color);
            font-weight: bold;
            position: absolute;
            left: 0;
        }
        
        .stats-section {
            background: linear-gradient(135deg, var(--primary-color), #2e86c1);
            color: white;
            padding: 60px 0;
        }
        
        .stat-item {
            text-align: center;
            padding: 20px;
        }
        
        .stat-number {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .stat-label {
            font-size: 18px;
            opacity: 0.9;
        }
        
        .testimonial-section {
            background-color: var(--light-bg);
            padding: 80px 0;
        }
        
        .testimonial-card {
            background-color: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            height: 100%;
            position: relative;
        }
        
        .testimonial-card:before {
            content: """;
            font-size: 80px;
            color: var(--primary-color);
            opacity: 0.1;
            position: absolute;
            top: 10px;
            left: 20px;
            font-family: Georgia, serif;
        }
        
        .testimonial-text {
            font-style: italic;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }
        
        .testimonial-author {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .testimonial-location {
            color: #6c757d;
            font-size: 14px;
        }
        
        .service-card {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: all 0.3s;
            height: 100%;
            margin-bottom: 30px;
            background-color: white;
        }
        
        .service-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
        }
        
        .service-icon {
            font-size: 40px;
            color: var(--primary-color);
            margin-bottom: 20px;
        }
        
        .live-data {
            background-color: #e8f5e9;
            border-left: 4px solid var(--secondary-color);
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .transaction-types {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
        }
        
        .transaction-badge {
            background-color: #e3f2fd;
            color: var(--primary-color);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .real-time-clock {
            background: linear-gradient(135deg, #2c3e50, #34495e);
            color: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .clock-time {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .clock-date {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .visitor-counter {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 10px 15px;
            border-radius: 5px;
            font-size: 14px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .hero-section {
                padding: 50px 0;
            }
            
            .partition-section {
                padding: 50px 0;
            }
            
            .stat-number {
                font-size: 36px;
            }
            
            .navbar-nav .nav-link {
                padding: 8px 15px !important;
            }
        }
    </style>
</head>
<body>
    <!-- Top Bar -->
    <div class="top-bar">
        <div class="container">
            <div class="d-flex justify-content-between">
                <div>
                    <i class="fas fa-clock me-1"></i> <?php echo $current_date; ?> | 
                    <i class="fas fa-users me-1 ms-2"></i> <?php echo number_format($visitor_count); ?> Active Visitors
                </div>
                <div>
                    <a href="#"><i class="fas fa-headset me-1"></i> Support</a>
                    <a href="#" class="ms-3"><i class="fas fa-globe me-1"></i> 
                        <select class="language-selector">
                            <option>English</option>
                            <option>Hindi</option>
                            <option>Odia</option>
                            <option>Telugu</option>
                        </select>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Banner -->
    <div class="notification-banner">
        <div class="container">
            <i class="fas fa-exclamation-circle"></i>
            <span id="notification-text">System maintenance scheduled for tonight 2:00 AM - 4:00 AM. Services may be temporarily unavailable.</span>
        </div>
    </div>

    <!-- Main Header -->
    <header class="main-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center py-3">
                <div class="logo">
                    <i class="fas fa-shield-alt"></i>
                    RuralSecure Bank
                </div>
                <!-- Header Quick Links -->
                <nav>
                    <ul class="list-inline mb-0">
                        <li class="list-inline-item"><a href="about.php" class="text-white text-decoration-none"><i class="fas fa-info-circle me-1"></i> About</a></li>
                        <li class="list-inline-item"><a href="services.php" class="text-white text-decoration-none"><i class="fas fa-concierge-bell me-1"></i> Features</a></li>
                        <li class="list-inline-item"><a href="news.php" class="text-white text-decoration-none"><i class="fas fa-newspaper me-1"></i> News</a></li>
                        <li class="list-inline-item"><a href="contact.php" class="text-white text-decoration-none"><i class="fas fa-phone me-1"></i> Contact</a></li>
                        <li class="list-inline-item"><a href="branches.php" class="text-white text-decoration-none"><i class="fas fa-map-marker-alt me-1"></i> Branches</a></li>
                        <li class="list-inline-item"><a href="faq.php" class="text-white text-decoration-none"><i class="fas fa-question-circle me-1"></i> FAQ</a></li>
                        <li class="list-inline-item"><a href="downloads.php" class="text-white text-decoration-none"><i class="fas fa-download me-1"></i> Downloads</a></li>
                    </ul>
                </nav>
                <div class="d-flex align-items-center">
                    <div class="dropdown me-2">
                        <button class="btn btn-outline-light btn-sm dropdown-toggle" type="button" id="loginDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user me-1"></i> Login
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="loginDropdown">
                            <li><a class="dropdown-item" href="staff-login.php"><i class="fas fa-user-tie me-1"></i> Staff Login</a></li>
                            <li><a class="dropdown-item" href="admin-login.php"><i class="fas fa-user-shield me-1"></i> Admin Login</a></li>
                        </ul>
                    </div>
                    <a href="#" class="btn btn-warning btn-sm"><i class="fas fa-download me-1"></i> Download App</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Navigation -->
    <nav class="navbar navbar-expand-lg main-navbar">
        <div class="container">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <?php
                    $current_page = basename($_SERVER['PHP_SELF']);
                    ?>
                    <!-- Home -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'index.php' ? 'active' : ''; ?>" href="index.php">
                            <i class="fas fa-home"></i> Home
                        </a>
                    </li>

                    <!-- Accounts -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array($current_page, ['savings-account.php', 'current-account.php', 'fixed-deposit.php', 'recurring-deposit.php']) ? 'active' : ''; ?>" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-piggy-bank"></i> Accounts
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="savings-account.php"><i class="fas fa-wallet"></i> Savings Account</a></li>
                            <li><a class="dropdown-item" href="current-account.php"><i class="fas fa-business-time"></i> Current Account</a></li>
                            <li><a class="dropdown-item" href="salary-account.php"><i class="fas fa-money-check"></i> Salary Account</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="fixed-deposit.php"><i class="fas fa-chart-bar"></i> Fixed Deposit</a></li>
                            <li><a class="dropdown-item" href="recurring-deposit.php"><i class="fas fa-calendar-alt"></i> Recurring Deposit</a></li>
                        </ul>
                    </li>

                    <!-- Loans -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array($current_page, ['personal-loan.php', 'home-loan.php', 'business-loan.php', 'agriculture-loan.php']) ? 'active' : ''; ?>" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-hand-holding-usd"></i> Loans
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="personal-loan.php"><i class="fas fa-user-tie"></i> Personal Loan</a></li>
                            <li><a class="dropdown-item" href="home-loan.php"><i class="fas fa-home"></i> Home Loan</a></li>
                            <li><a class="dropdown-item" href="car-loan.php"><i class="fas fa-car"></i> Car Loan</a></li>
                            <li><a class="dropdown-item" href="education-loan.php"><i class="fas fa-graduation-cap"></i> Education Loan</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="business-loan.php"><i class="fas fa-briefcase"></i> Business Loan</a></li>
                            <li><a class="dropdown-item" href="agriculture-loan.php"><i class="fas fa-tractor"></i> Agriculture Loan</a></li>
                            <li><a class="dropdown-item" href="gold-loan.php"><i class="fas fa-gem"></i> Gold Loan</a></li>
                        </ul>
                    </li>

                    <!-- Services -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array($current_page, ['digital-banking.php', 'mobile-banking.php', 'net-banking.php', 'card-services.php']) ? 'active' : ''; ?>" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-concierge-bell"></i> Services
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="digital-banking.php"><i class="fas fa-mobile-alt"></i> Digital Banking</a></li>
                            <li><a class="dropdown-item" href="mobile-banking.php"><i class="fas fa-tablet-alt"></i> Mobile Banking</a></li>
                            <li><a class="dropdown-item" href="net-banking.php"><i class="fas fa-laptop"></i> Internet Banking</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="card-services.php"><i class="fas fa-credit-card"></i> Card Services</a></li>
                            <li><a class="dropdown-item" href="insurance.php"><i class="fas fa-shield-alt"></i> Insurance</a></li>
                            <li><a class="dropdown-item" href="investment.php"><i class="fas fa-chart-pie"></i> Investments</a></li>
                            <li><a class="dropdown-item" href="demat-account.php"><i class="fas fa-chart-line"></i> Demat Account</a></li>
                        </ul>
                    </li>

                    <!-- Payments -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array($current_page, ['bill-payment.php', 'fund-transfer.php', 'recharge.php', 'tax-payment.php']) ? 'active' : ''; ?>" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-money-bill-wave"></i> Payments
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="fund-transfer.php"><i class="fas fa-exchange-alt"></i> Fund Transfer</a></li>
                            <li><a class="dropdown-item" href="bill-payment.php"><i class="fas fa-file-invoice-dollar"></i> Bill Payment</a></li>
                            <li><a class="dropdown-item" href="recharge.php"><i class="fas fa-mobile-alt"></i> Mobile Recharge</a></li>
                            <li><a class="dropdown-item" href="tax-payment.php"><i class="fas fa-receipt"></i> Tax Payment</a></li>
                            <li><a class="dropdown-item" href="donation.php"><i class="fas fa-hand-holding-heart"></i> Donations</a></li>
                        </ul>
                    </li>

                    <!-- Government Schemes -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array($current_page, ['pradhan-mantri.php', 'state-schemes.php', 'social-security.php']) ? 'active' : ''; ?>" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-landmark"></i> Govt Schemes
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="pradhan-mantri.php"><i class="fas fa-flag"></i> Pradhan Mantri Schemes</a></li>
                            <li><a class="dropdown-item" href="state-schemes.php"><i class="fas fa-map"></i> State Government Schemes</a></li>
                            <li><a class="dropdown-item" href="social-security.php"><i class="fas fa-user-shield"></i> Social Security</a></li>
                            <li><a class="dropdown-item" href="pension-schemes.php"><i class="fas fa-umbrella"></i> Pension Schemes</a></li>
                        </ul>
                    </li>

                    <!-- About Us -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array($current_page, ['about.php', 'careers.php', 'news.php', 'contact.php']) ? 'active' : ''; ?>" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-info-circle"></i> About Us
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="about.php"><i class="fas fa-building"></i> About RuralSecure</a></li>
                            <li><a class="dropdown-item" href="vision.php"><i class="fas fa-eye"></i> Vision & Mission</a></li>
                            <li><a class="dropdown-item" href="leadership.php"><i class="fas fa-users"></i> Leadership</a></li>
                            <li><a class="dropdown-item" href="careers.php"><i class="fas fa-briefcase"></i> Careers</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="news.php"><i class="fas fa-newspaper"></i> News & Updates</a></li>
                            <li><a class="dropdown-item" href="contact.php"><i class="fas fa-phone"></i> Contact Us</a></li>
                            <li><a class="dropdown-item" href="branches.php"><i class="fas fa-map-marker-alt"></i> Branch Network</a></li>
                        </ul>
                    </li>

                    <!-- Security -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'security.php' ? 'active' : ''; ?>" href="security.php">
                            <i class="fas fa-shield-alt"></i> Security
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == '.php' ? 'active' : ''; ?>" href="security.php">
                            <i class="fas fa-user-plus"></i> Register / Open Account
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Real-time Clock -->
    <div class="container mt-3">
        <div class="real-time-clock">
            <div class="clock-time" id="live-clock"><?php echo $current_time; ?></div>
            <div class="clock-date"><?php echo $current_date; ?></div>
        </div>
    </div>

    <!-- Rest of the page content remains the same -->
    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container hero-content">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h1 class="display-4 fw-bold mb-4">Secure Digital Banking for Rural India</h1>
                    <p class="lead mb-4">Our lightweight cybersecurity framework protects your transactions with advanced fraud detection and secure authentication, designed specifically for rural users.</p>
                    <div class="visitor-counter">
                        <i class="fas fa-user-check me-1"></i> <strong><?php echo number_format($user_count['count']); ?>+</strong> satisfied customers trust our secure banking platform
                    </div>
                    <div class="d-flex flex-wrap gap-3">
                        <button class="btn btn-warning btn-lg"><i class="fas fa-mobile-alt me-2"></i> Download App</button>
                        <button class="btn btn-outline-light btn-lg"><i class="fas fa-play-circle me-2"></i> Watch Demo</button>
                    </div>
                </div>
                <div class="col-lg-6 text-center">
                    <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80" alt="Secure Banking" class="img-fluid rounded shadow" style="max-height: 400px;">
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats-section">
        <div class="container">
            <div class="row text-center">
                <div class="col-md-3 col-6">
                    <div class="stat-item">
                        <div class="stat-number" id="fraud-reduction"><?php echo $stats['fraud_reduction']; ?>%</div>
                        <div class="stat-label">Reduction in Fraud</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-item">
                        <div class="stat-number" id="users-protected"><?php echo number_format($stats['users_protected']); ?>+</div>
                        <div class="stat-label">Users Protected</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-item">
                        <div class="stat-number" id="transactions-secure"><?php echo number_format($stats['transactions_secured']); ?>+</div>
                        <div class="stat-label">Secure Transactions</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-item">
                        <div class="stat-number" id="villages-covered"><?php echo number_format($stats['villages_covered']); ?>+</div>
                        <div class="stat-label">Villages Covered</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Two Partition Section -->
    <section class="partition-section partition-2">
        <div class="container">
            <h2 class="text-center section-title">Banking Services</h2>
            <p class="text-center mb-5">Secure and easy-to-use banking solutions for rural customers</p>
            
            <div class="row g-5">
                <!-- Partition 1: Open Account Securely -->
                <div class="col-lg-6">
                    <div class="partition-card">
                        <div class="partition-card-header">
                            <h3><i class="fas fa-user-plus me-2"></i> Open Account Securely</h3>
                        </div>
                        <div class="partition-card-body">
                            <p class="lead">Create your bank account with our secure registration process designed for rural users.</p>
                            
                            <div class="live-data">
                                <i class="fas fa-bolt me-2"></i> <strong>Live Update:</strong> <span id="accounts-opened-today"><?php echo rand(50, 150); ?></span> accounts opened today
                            </div>
                            
                            <div class="security-features">
                                <h5><i class="fas fa-shield-alt me-2"></i> Security Features:</h5>
                                <ul class="feature-list">
                                    <li>Biometric verification during registration</li>
                                    <li>Document upload with encryption</li>
                                    <li>Secure OTP validation</li>
                                    <li>Video KYC option available</li>
                                    <li>End-to-end encrypted data transmission</li>
                                </ul>
                            </div>
                            
                            <div class="mt-4">
                                <h5>How to Open an Account:</h5>
                                <ol>
                                    <li>Download our secure banking app</li>
                                    <li>Complete identity verification</li>
                                    <li>Submit required documents</li>
                                    <li>Set up security PIN</li>
                                    <li>Activate your account</li>
                                </ol>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-start mt-4">
                                <button class="btn btn-primary-custom me-md-2"><i class="fas fa-user-plus me-2"></i> Open Account Now</button>
                                <button class="btn btn-outline-primary"><i class="fas fa-question-circle me-2"></i> Learn More</button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Partition 2: Transaction System -->
                <div class="col-lg-6">
                    <div class="partition-card">
                        <div class="partition-card-header">
                            <h3><i class="fas fa-exchange-alt me-2"></i> Secure Transaction System</h3>
                        </div>
                        <div class="partition-card-body">
                            <p class="lead">Perform banking transactions securely with our advanced protection system.</p>
                            
                            <div class="live-data">
                                <i class="fas fa-bolt me-2"></i> <strong>Live Update:</strong> <span id="transactions-today"><?php echo number_format(rand(5000, 15000)); ?></span> secure transactions today
                            </div>
                            
                            <div class="security-features">
                                <h5><i class="fas fa-lock me-2"></i> Transaction Security:</h5>
                                <ul class="feature-list">
                                    <li>Two-factor authentication for every transaction</li>
                                    <li>Real-time fraud detection using AI</li>
                                    <li>Offline transaction capability</li>
                                    <li>Encrypted transaction data</li>
                                    <li>Instant transaction alerts</li>
                                </ul>
                            </div>
                            
                            <div class="mt-4">
                                <h5>Available Transactions:</h5>
                                <div class="transaction-types">
                                    <?php
                                    if ($transaction_types_result && $transaction_types_result->num_rows > 0) {
                                        while($transaction_type = $transaction_types_result->fetch_assoc()) {
                                            echo '<span class="transaction-badge">'.$transaction_type['name'].'</span>';
                                        }
                                    } else {
                                        // Default transaction types
                                        $default_types = ['Fund Transfer', 'Bill Payments', 'Mobile Recharge', 'Loan Applications', 'Insurance Payments', 'Government Schemes'];
                                        foreach($default_types as $type) {
                                            echo '<span class="transaction-badge">'.$type.'</span>';
                                        }
                                    }
                                    ?>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-start mt-4">
                                <button class="btn btn-success-custom me-md-2"><i class="fas fa-sign-in-alt me-2"></i> Login to Transact</button>
                                <button class="btn btn-outline-success"><i class="fas fa-download me-2"></i> Download App</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="partition-section partition-1">
        <div class="container">
            <h2 class="text-center section-title">Our Security Features</h2>
            <p class="text-center mb-5">Designed specifically for low-end smartphones and limited internet connectivity in rural areas</p>
            
            <div class="row g-4">
                <?php
                if ($services_result && $services_result->num_rows > 0) {
                    while($service = $services_result->fetch_assoc()) {
                        echo '
                        <div class="col-md-4">
                            <div class="card feature-card">
                                <div class="card-body text-center p-4">
                                    <div class="feature-icon">
                                        <i class="'.$service['icon'].'"></i>
                                    </div>
                                    <h4>'.$service['title'].'</h4>
                                    <p>'.$service['description'].'</p>
                                    <div class="security-badge">'.$service['badge_text'].'</div>
                                </div>
                            </div>
                        </div>';
                    }
                } else {
                    // Default services if none in database
                    echo '
                    <div class="col-md-4">
                        <div class="card feature-card">
                            <div class="card-body text-center p-4">
                                <div class="feature-icon">
                                    <i class="fas fa-fingerprint"></i>
                                </div>
                                <h4>Two-Factor Authentication</h4>
                                <p>Secure login with OTP + biometric/PIN verification to ensure only authorized access to your account.</p>
                                <div class="security-badge">Advanced Security</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card feature-card">
                            <div class="card-body text-center p-4">
                                <div class="feature-icon">
                                    <i class="fas fa-brain"></i>
                                </div>
                                <h4>AI Fraud Detection</h4>
                                <p>Lightweight machine learning models detect suspicious transactions in real-time, even on low-resource devices.</p>
                                <div class="security-badge">Smart Protection</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card feature-card">
                            <div class="card-body text-center p-4">
                                <div class="feature-icon">
                                    <i class="fas fa-wifi-slash"></i>
                                </div>
                                <h4>Offline-First Design</h4>
                                <p>Initiate and validate transactions offline, with automatic sync when connection is restored.</p>
                                <div class="security-badge">Connectivity Solution</div>
                            </div>
                        </div>
                    </div>';
                }
                ?>
            </div>
        </div>
    </section>

    <!-- Banner Carousel -->
    <section class="container my-5">
        <div id="bannerCarousel" class="carousel slide banner-carousel" data-bs-ride="carousel">
            <div class="carousel-indicators">
                <?php
                if ($banner_result && $banner_result->num_rows > 0) {
                    $banner_count = 0;
                    while($banner = $banner_result->fetch_assoc()) {
                        $active_class = $banner_count == 0 ? 'active' : '';
                        echo '<button type="button" data-bs-target="#bannerCarousel" data-bs-slide-to="'.$banner_count.'" class="'.$active_class.'" aria-current="true" aria-label="Slide '.($banner_count+1).'"></button>';
                        $banner_count++;
                    }
                } else {
                    // Default banners if none in database
                    echo '
                    <button type="button" data-bs-target="#bannerCarousel" data-bs-slide-to="0" class="active" aria-current="true" aria-label="Slide 1"></button>
                    <button type="button" data-bs-target="#bannerCarousel" data-bs-slide-to="1" aria-label="Slide 2"></button>
                    <button type="button" data-bs-target="#bannerCarousel" data-bs-slide-to="2" aria-label="Slide 3"></button>';
                }
                ?>
            </div>
            <div class="carousel-inner">
                <?php
                if ($banner_result && $banner_result->num_rows > 0) {
                    // Reset pointer to beginning
                    $banner_result->data_seek(0);
                    $banner_count = 0;
                    while($banner = $banner_result->fetch_assoc()) {
                        $active_class = $banner_count == 0 ? 'active' : '';
                        echo '
                        <div class="carousel-item '.$active_class.'">
                            <img src="'.$banner['image_url'].'" class="d-block w-100" alt="'.$banner['title'].'" style="height: 400px; object-fit: cover;">
                            <div class="carousel-caption d-none d-md-block">
                                <h5>'.$banner['title'].'</h5>
                                <p>'.$banner['description'].'</p>
                            </div>
                        </div>';
                        $banner_count++;
                    }
                } else {
                    // Default banners if none in database
                    echo '
                    <div class="carousel-item active">
                        <img src="https://images.unsplash.com/photo-1554224155-6726b3ff858f?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80" class="d-block w-100" alt="Secure Banking" style="height: 400px; object-fit: cover;">
                        <div class="carousel-caption d-none d-md-block">
                            <h5>Bank Securely from Anywhere</h5>
                            <p>Our framework ensures your transactions are protected with military-grade encryption</p>
                        </div>
                    </div>
                    <div class="carousel-item">
                        <img src="https://images.unsplash.com/photo-1563017941-7b4e414d0d5e?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80" class="d-block w-100" alt="Rural Banking" style="height: 400px; object-fit: cover;">
                        <div class="carousel-caption d-none d-md-block">
                            <h5>Designed for Rural India</h5>
                            <p>Lightweight solution optimized for low-end smartphones and limited connectivity</p>
                        </div>
                    </div>
                    <div class="carousel-item">
                        <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80" class="d-block w-100" alt="Fraud Protection" style="height: 400px; object-fit: cover;">
                        <div class="carousel-caption d-none d-md-block">
                            <h5>Advanced Fraud Detection</h5>
                            <p>AI-powered system reduces fraud incidents by over 20%</p>
                        </div>
                    </div>';
                }
                ?>
            </div>
            <button class="carousel-control-prev" type="button" data-bs-target="#bannerCarousel" data-bs-slide="prev">
                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Previous</span>
            </button>
            <button class="carousel-control-next" type="button" data-bs-target="#bannerCarousel" data-bs-slide="next">
                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Next</span>
            </button>
        </div>
    </section>

    <!-- Testimonials Section -->
    <section class="testimonial-section">
        <div class="container">
            <h2 class="text-center section-title">What Our Customers Say</h2>
            <p class="text-center mb-5">Hear from rural users who have experienced secure banking with our framework</p>
            
            <div class="row">
                <?php
                if ($testimonials_result && $testimonials_result->num_rows > 0) {
                    while($testimonial = $testimonials_result->fetch_assoc()) {
                        echo '
                        <div class="col-md-6 col-lg-3">
                            <div class="testimonial-card">
                                <div class="testimonial-text">
                                    '.$testimonial['content'].'
                                </div>
                                <div class="testimonial-author">'.$testimonial['author'].'</div>
                                <div class="testimonial-location">'.$testimonial['location'].'</div>
                            </div>
                        </div>';
                    }
                } else {
                    // Default testimonials if none in database
                    echo '
                    <div class="col-md-6 col-lg-3">
                        <div class="testimonial-card">
                            <div class="testimonial-text">
                                This banking app works perfectly even in my village with poor network. The offline transaction feature is a lifesaver!
                            </div>
                            <div class="testimonial-author">Rajesh Kumar</div>
                            <div class="testimonial-location">Sundargarh, Odisha</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="testimonial-card">
                            <div class="testimonial-text">
                                I feel much safer using this app. The two-step verification gives me confidence that my money is protected.
                            </div>
                            <div class="testimonial-author">Priya Singh</div>
                            <div class="testimonial-location">Kalahandi, Odisha</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="testimonial-card">
                            <div class="testimonial-text">
                                As a farmer, I need to send money to my children in the city. This app makes it simple and secure.
                            </div>
                            <div class="testimonial-author">Biswanath Patra</div>
                            <div class="testimonial-location">Koraput, Odisha</div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="testimonial-card">
                            <div class="testimonial-text">
                                The interface is so easy to use, even for someone like me who is not very familiar with smartphones.
                            </div>
                            <div class="testimonial-author">Meera Behera</div>
                            <div class="testimonial-location">Ganjam, Odisha</div>
                        </div>
                    </div>';
                }
                ?>
            </div>
        </div>
    </section>

    <!-- News Section -->
    <section class="news-section">
        <div class="container">
            <h2 class="text-center section-title">Latest News & Updates</h2>
            <p class="text-center mb-5">Stay informed about our latest security features and banking services</p>
            
            <div class="row">
                <?php
                if ($news_result && $news_result->num_rows > 0) {
                    while($news = $news_result->fetch_assoc()) {
                        echo '
                        <div class="col-md-4">
                            <div class="card news-card">
                                <div class="card-body">
                                    <span class="news-date"><i class="far fa-calendar me-1"></i> '.date('M j, Y', strtotime($news['created_at'])).'</span>
                                    <h5 class="card-title mt-2">'.$news['title'].'</h5>
                                    <p class="card-text">'.substr($news['content'], 0, 120).'...</p>
                                    <a href="#" class="btn btn-sm btn-outline-primary">Read More</a>
                                </div>
                            </div>
                        </div>';
                    }
                } else {
                    // Default news if none in database
                    echo '
                    <div class="col-md-4">
                        <div class="card news-card">
                            <div class="card-body">
                                <span class="news-date"><i class="far fa-calendar me-1"></i> Jun 15, 2023</span>
                                <h5 class="card-title mt-2">New Security Update Released</h5>
                                <p class="card-text">We\'ve enhanced our fraud detection algorithms to provide even better protection for rural banking transactions.</p>
                                <a href="#" class="btn btn-sm btn-outline-primary">Read More</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card news-card">
                            <div class="card-body">
                                <span class="news-date"><i class="far fa-calendar me-1"></i> May 28, 2023</span>
                                <h5 class="card-title mt-2">Partnership with Local Banks</h5>
                                <p class="card-text">We\'ve partnered with 50+ regional rural banks to expand our secure banking services to more villages.</p>
                                <a href="#" class="btn btn-sm btn-outline-primary">Read More</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card news-card">
                            <div class="card-body">
                                <span class="news-date"><i class="far fa-calendar me-1"></i> Apr 10, 2023</span>
                                <h5 class="card-title mt-2">Offline Transaction Feature</h5>
                                <p class="card-text">Our new offline transaction capability allows users to initiate payments even without internet connection.</p>
                                <a href="#" class="btn btn-sm btn-outline-primary">Read More</a>
                            </div>
                        </div>
                    </div>';
                }
                ?>
            </div>
            
            <div class="text-center mt-4">
                <button class="btn btn-primary-custom"><i class="fas fa-newspaper me-2"></i> View All News</button>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <h5><i class="fas fa-shield-alt me-2"></i> RuralSecure Bank</h5>
                    <p>A lightweight cybersecurity framework for secure digital banking in rural areas, focusing on fraud detection and user authentication.</p>
                    <div class="mt-3">
                        <a href="#" class="text-white me-3"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="text-white me-3"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="text-white me-3"><i class="fab fa-linkedin-in"></i></a>
                        <a href="#" class="text-white"><i class="fab fa-instagram"></i></a>
                    </div>
                </div>
                <div class="col-lg-2 col-md-6 mb-4">
                    <h5>Quick Links</h5>
                    <div class="footer-links">
                        <a href="#">Home</a>
                        <a href="#">About Us</a>
                        <a href="#">Services</a>
                        <a href="#">Security</a>
                        <a href="#">Contact</a>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-4">
                    <h5>Services</h5>
                    <div class="footer-links">
                        <a href="#">Open Account</a>
                        <a href="#">Fund Transfer</a>
                        <a href="#">Bill Payments</a>
                        <a href="#">Loans</a>
                        <a href="#">Insurance</a>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-4">
                    <h5>Contact Us</h5>
                    <div class="footer-links">
                        <p><i class="fas fa-map-marker-alt me-2"></i> 123 Banking Street, Financial District</p>
                        <p><i class="fas fa-phone me-2"></i> +91 1800-123-4567</p>
                        <p><i class="fas fa-envelope me-2"></i> support@ruralsecurebank.com</p>
                    </div>
                </div>
            </div>
            
            <div class="copyright">
                <p>&copy; 2023 RuralSecure Bank. All rights reserved. | Developed as part of SIH25205 - Cybersecurity Framework for Rural Digital Banking</p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Real-time clock update
        function updateClock() {
            const now = new Date();
            const time = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
            const date = now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            
            document.getElementById('live-clock').textContent = time;
            document.querySelector('.clock-date').textContent = date;
        }
        
        // Update clock immediately and then every second
        updateClock();
        setInterval(updateClock, 1000);
        
        // Animated counter for stats
        function animateCounter(elementId, target, duration = 2000) {
            const element = document.getElementById(elementId);
            const start = parseInt(element.textContent.replace(/,/g, ''));
            const increment = (target - start) / (duration / 16);
            let current = start;
            
            const timer = setInterval(() => {
                current += increment;
                if ((increment > 0 && current >= target) || (increment < 0 && current <= target)) {
                    clearInterval(timer);
                    current = target;
                }
                
                if (elementId === 'fraud-reduction') {
                    element.textContent = Math.round(current) + '%';
                } else {
                    element.textContent = Math.round(current).toLocaleString() + '+';
                }
            }, 16);
        }
        
        // Initialize counters when page loads
        window.addEventListener('load', function() {
            animateCounter('fraud-reduction', <?php echo $stats['fraud_reduction']; ?>);
            animateCounter('users-protected', <?php echo $stats['users_protected']; ?>);
            animateCounter('transactions-secure', <?php echo $stats['transactions_secured']; ?>);
            animateCounter('villages-covered', <?php echo $stats['villages_covered']; ?>);
        });
        
        // Update live data periodically
        function updateLiveData() {
            // Simulate changing data
            document.getElementById('accounts-opened-today').textContent = Math.floor(Math.random() * 100) + 50;
            document.getElementById('transactions-today').textContent = (Math.floor(Math.random() * 10000) + 5000).toLocaleString();
        }
        
        // Update live data every 30 seconds
        setInterval(updateLiveData, 30000);
        
        // Notification rotation
        const notifications = [
            "System maintenance scheduled for tonight 2:00 AM - 4:00 AM. Services may be temporarily unavailable.",
            "New security update available! Download the latest version of our app for enhanced protection.",
            "Welcome to our new customers from Mayurbhanj district! Special offers available for first-time users.",
            "RuralSecure Bank now supports transactions in 5 regional languages for better accessibility."
        ];
        
        let currentNotification = 0;
        
        function rotateNotification() {
            document.getElementById('notification-text').textContent = notifications[currentNotification];
            currentNotification = (currentNotification + 1) % notifications.length;
        }
        
        // Rotate notifications every 10 seconds
        setInterval(rotateNotification, 10000);
    </script>
</body>
</html>

<?php
// Close database connection
$conn->close();
?>