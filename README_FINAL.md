# EGRADEPRO: An AI-Assisted Grading and Enrollment Management System

EGRADEPRO is a comprehensive, AI-assisted web-based platform that integrates grading and enrollment management for educational institutions. It features dedicated and personalized portals for Students, Teachers, and Administrators, allowing each user type to manage their specific tasks efficiently. With built-in AI assistance for teachers, the system aims to streamline academic workflows, secure student records, and modernize the school's overall administrative processes.

## Group Members & Roles
* **[Tejada, Francis T.]** –  Lead Developer / API Identifier, / loT monitoring 
* **[Nuñez, Jay M.]** – Programmer / AI Assisted 
* **[Evangelista, Mark Christopher M.]** – Main UI Consultant  / UI/UX Animation
* **[Blancaver, Alexander O.]** – Software Developer / Debugger 
* **[Robles, France Jhoruis T. ]** – Main UI Consultant / UI/UX Animation / Documentation

---

## Tech Stack
* **Frontend:** React.js, TypeScript, Tailwind CSS, Radix UI, Lucide React, Recharts
* **Backend:** PHP, Node.js
* **Database:** MySQL
* **Tools & Libraries:** Vite, TanStack Start, Composer, PHPMailer, Dompdf, React Hook Form, Zod
* **Cloud Services:** Cloudflare (Tunneling), Google Cloud Console, AI Studio
* **Version Control:** Git, VS Code

---

## System Requirements & Prerequisites
Before you begin, ensure you have the following installed on your local machine:
* [e.g., Node.js (v18+)]
* [e.g., PHP (v8.1+)]
* [e.g., Composer / XAMPP / MySQL Server]

---

## Installation & Setup Instructions

Follow these step-by-step commands to set up the project locally:

### 1. Clone the Repository
git clone https://github.com/francistejada123/Darksystem-Final.git
cd Darksystem-Final

### 2. Install Dependencies
# For Node.js projects:
npm install

# For PHP/Laravel projects:
composer install
cp .env.example .env
php artisan key:generate

---

## Database Setup & Configuration

Follow these steps to configure your local database:

1. **Start your local database server** (e.g., open XAMPP and start **Apache** and **MySQL**).
2. **Create a new database:**
   * Open phpMyAdmin (`http://localhost/phpmyadmin`) or your database client.
   * Create a new database named: `db_darksystem` (Palitan ito ng totoong pangalan ng database niyo).
3. **Import the Database Schema:**
   * Locate the SQL export file inside the repository folder (usually found under `/database/db_backup.sql` or `/sql/schema.sql`).
   * Import this file into your newly created database.
4. **Configure Environment Variables:**
   * Open the `.env` file in the root directory.
   * Update your database connection strings:
     ```env
     DB_CONNECTION=mysql
     DB_HOST=127.0.0.1
     DB_PORT=3306
     DB_DATABASE=db_darksystem
     DB_USERNAME=root
     DB_PASSWORD=
---

##  Running the Application

# Start the frontend development server (Vite/React):
npm run dev

# For the backend (PHP):
# If using XAMPP, move the project folder to the 'htdocs' directory.
# Open your browser and navigate to: `http://localhost/Darksystem-Final`

---

## Default Login Credentials
* **Admin Account:** `[admin]` / `[admin123]`
* **Teacher Acciount:** `[francistejada88@gmail.com]` / `[teacher123]`
* **Student Account:** `[110100100056]` / `[student123]`
