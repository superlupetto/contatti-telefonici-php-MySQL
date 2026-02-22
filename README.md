# 📌 User Management Panel

Sistema di gestione utenti sviluppato in PHP + MySQL, con pannello Admin e gestione ruoli.

## 🚀 Funzionalità

- Registrazione
- Login / Logout
- Cambio password personale
- Gestione utenti (admin)
- Reset password utenti
- Attiva / Disattiva account
- Eliminazione utenti
- Protezione CSRF
- Password hash sicuro

## 🏷 Ruoli

- admin
- user

Il ruolo moderator è stato rimosso.

## 🛠 Requisiti

- PHP 8+
- MySQL / MariaDB
- Apache / Nginx

## 🗄 Struttura Database

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','user') NOT NULL DEFAULT 'user',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

## 🔄 Installazione

1. Caricare i file sul server
2. Creare database
3. Configurare credenziali DB in index.php
4. Accedere al pannello
5. Creare primo admin

Versione: 1.0
