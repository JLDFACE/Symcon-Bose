# Symcon Bose Control

IP-Symcon Modul zur Steuerung professioneller Bose Audio Prozessoren
(**Bose ESP880A**, **Bose EX1280**) über Ethernet.

Dieses Modul ist für den stabilen Dauerbetrieb auf der **SymBox (IP-Symcon 8.1)** optimiert und unterstützt
flüssige Lautstärkefahrten (Gain-Fades) ohne Verbindungsabbrüche oder Performanceprobleme.

---

## Unterstützte Geräte

- Bose ESP880A
- Bose EX1280

(Weitere ESP/EX-Modelle mit identischem Protokoll sollten ebenfalls funktionieren.)

---

## Modulübersicht

Das Repository enthält folgende Module:

- **Bose Device**  
  Zentrale Geräteinstanz, verwaltet die TCP/IP-Verbindung zum Bose Prozessor.

- **Bose Module Gain**  
  Steuerung einzelner Gain-Kanäle (inkl. flüssiger Fades).

- **Bose Module Source Selector**  
  Umschaltung von Quellen / Presets.

---

## Installation

1. In IP-Symcon:
   - Modulverwaltung → **Repositories**
   - Repository hinzufügen:
     ```
     https://github.com/JLDFACE/Symcon-Bose
     ```

2. Module installieren
3. Instanzen wie gewohnt anlegen

### Hinweis SymBox (Caching)
Bei Problemen nach Updates:
1. Repository entfernen
2. SymBox neu starten
3. Repository erneut hinzufügen

---

## Konfiguration

### Netzwerk / Port
- Die Verbindung erfolgt über eine **ClientSocket-Instanz**
- **Port und IP-Adresse bleiben frei konfigurierbar**, da der Port am Bose-Gerät variabel ist
- Standardmäßig wird eine dauerhafte TCP-Verbindung genutzt

### Verhalten bei Bose-Programmierung
Während der Programmierung über die Bose-Software ist der TCP-Port am Gerät **geschlossen**.

Das Modul erkennt diesen Zustand und:
- vermeidet aggressive Reconnect-Schleifen
- belastet die SymBox nicht unnötig
- nimmt nach Ende der Programmierung automatisch wieder den Betrieb auf

---

## Gain-Fades / Lautstärkefahrten

- Gain-Änderungen werden **ohne künstliche Drosselung** gesendet
- Solange die Verbindung aktiv ist, werden alle Werte **sofort übertragen**
- Bei kurzzeitigem Verbindungsverlust wird der **letzte Sollwert gespeichert** und nach Reconnect gesendet
- Dadurch bleiben Lautstärken konsistent, auch bei Unterbrechungen

---

## Technische Hinweise

- Optimiert für **hohe Command-Raten** (z. B. Fades)
- Kein Connection-Thrashing (kein permanentes Open/Close)
- Reconnect-Backoff bei Port-Fehlern
- SymBox- und Kernel-schonendes Verhalten

---

## Anforderungen

- IP-Symcon **8.1**
- SymBox (empfohlen)
- Netzwerkverbindung zum Bose Audio Prozessor

---

## Lizenz / Autor

Autor: **FACE GmbH**

Dieses Modul wurde für den professionellen Einsatz in Medien- und Gebäudetechnik entwickelt.

