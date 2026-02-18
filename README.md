# contatti
# 📇 Google-Style Contact Manager

Un'applicazione web self-hosted, leggera e moderna per la gestione dei contatti. Il design è ispirato a Google Contacts, offrendo un'esperienza utente fluida con visualizzazione a schede e gestione completa dei dati.

## 🚀 Caratteristiche principali

-   **Interfaccia Google Contacts**: Layout pulito con sidebar, ricerca istantanea e schede profilo.
-   **Sistema "View-then-Edit"**: Cliccando su un contatto si apre la scheda di dettaglio; da lì è possibile modificare i dati tramite l'icona della matita (✎).
-   **Gestione Immagini**: Supporto per il caricamento di foto profilo. Se non presente, il sistema genera un avatar colorato con l'iniziale.
-   **Ricerca Avanzata**: Filtro dinamico dei contatti in tempo reale.
-   **Preferiti**: Funzione per contrassegnare i contatti importanti e visualizzarli in cima alla lista.
-   **Database JSON**: Non richiede MySQL. I dati sono salvati in modo portabile in un file `.json`.
-   **Protezione Accesso**: Login integrato con sessione sicura.

---

## 🛠️ Requisiti Tecnici

-   **Server Web**: Apache o Nginx.
-   **PHP**: Versione 7.4 o superiore.
-   **Estensioni PHP**: `json`, `session`, `fileinfo`.
-   **Permessi di scrittura**: Necessari per il salvataggio dei file JSON e delle immagini.

---

## 📂 Struttura del Progetto

```text
/var/www/html/
├── index.php             # L'intero codice (Logica, CSS, JS e HTML)
├── README.md             # Documentazione del progetto
└── uploadslist/          # Directory creata automaticamente
    ├── contatti.json     # Il "database" dei contatti
    └── *.jpg/png         # Immagini del profilo caricate

1. Clonazione o Copia
Copia il file index.php nella cartella root del tuo server web (es. /var/www/html/).

2. Configurazione Permessi (Fondamentale)
Per permettere a PHP di scrivere i dati e salvare le immagini, assegna la proprietà della cartella all'utente del web server:

    sudo chown -R www-data:www-data /var/www/html/
    sudo chmod -R 755 /var/www/html/
