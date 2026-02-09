# 🏦 Rural Banking System – Internet Banking

The **Rural Banking System – Internet Banking** is a web-based banking application designed to provide basic and essential digital banking services for rural and semi-urban users.  
The system focuses on simplicity, security, and accessibility, enabling users to perform banking operations online without visiting a physical branch.

---

## 🌾 Overview

This system digitizes rural banking operations by allowing customers, bank staff, and administrators to manage accounts, transactions, and user data efficiently.  
It aims to improve financial inclusion and make banking services easily accessible in rural areas.

---

## ✨ Key Features

### 👤 User (Customer) Features
- User registration and secure login
- View account balance
- Deposit money (via staff/admin approval)
- Withdraw money
- Fund transfer between accounts
- View transaction history
- Update personal profile
- Change password

---

### 🧑‍💼 Staff Features
- Staff login
- Create new user accounts
- Verify and approve users
- Deposit money to user accounts
- Assist with withdrawals
- View customer account details
- Monitor daily transactions

---

### 🛠️ Admin Features
- Admin dashboard
- Add / remove staff
- Manage users and staff accounts
- Approve or block accounts
- Monitor all transactions
- System-wide control and reporting
- Security and role management

---

## 🛠️ Tech Stack

### Frontend
- HTML
- CSS
- JavaScript
- Bootstrap

### Backend
- PHP

### Database
- MySQL

### Tools
- XAMPP
- VS Code / Sublime Text
- phpMyAdmin
- GitHub

---

## 🗄️ Database Overview

Main tables used in the system:
- users
- staff
- admin
- accounts
- transactions
- login_logs

---

## 🚀 How to Run the Project

### 1️⃣ Clone the repository
```bash
git clone https://github.com/your-username/rural-banking-system.git
````

### 2️⃣ Move project to XAMPP

```text
C:/xampp/htdocs/rural-banking-system
```

### 3️⃣ Start Server

* Open XAMPP Control Panel
* Start **Apache** and **MySQL**

### 4️⃣ Database Setup

* Open `phpMyAdmin`
* Create a database (e.g., `rural_banking`)
* Import the provided `.sql` file

### 5️⃣ Run the Application

```text
http://localhost/rural-banking-system
```

---

## 🔒 Security Features

* Password hashing
* Role-based access (User / Staff / Admin)
* Session management
* Input validation (client & server side)
* SQL injection prevention using prepared statements

---

## 📈 Future Enhancements

* Mobile banking application
* SMS alerts for transactions
* UPI / payment gateway integration
* Loan and EMI management
* Aadhaar / KYC verification
* Multi-language support for rural users

---

## 🤝 Contributing

Contributions are welcome 🚀

You can contribute by:

* Adding new banking features
* Improving security
* Enhancing UI/UX
* Optimizing database queries
* Improving documentation

---

## 📜 License

Copyright © 2026 Soumya Das

This project is owned and maintained by the author.
You are free to use, modify, and contribute with proper credit.

Licensed under the **MIT License**.

---

⭐ If you find this project useful, feel free to star the repository!
