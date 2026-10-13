# Symcon Bose Control

IP-Symcon Modul zur Steuerung professioneller **Bose Audio Prozessoren**
über Ethernet, optimiert für den stabilen Dauerbetrieb auf der **SymBox
(IP-Symcon 8.1)**.

Der Fokus dieses Moduls liegt auf:
- **extrem flüssigen Gain-Fades (Lautstärken)**
- stabiler TCP-Kommunikation ohne Reconnect-Thrashing
- deutlich reduzierter Systemlast durch intelligentes Polling

---

## Unterstützte Geräte

- Bose ESP880A  
- Bose EX1280  

(Weitere ESP/EX-Modelle mit identischem Protokoll sollten ebenfalls funktionieren.)

---

## Modulübersicht

Dieses Repository enthält folgende Module:

### Bose Device
Zentrale Geräteinstanz.
- Verwaltet die TCP/IP-Verbindung zum Bose-Prozessor
- Zuständig für Online-Status, Grundkommunikation und Senden von Befehlen

### Bose Module Gain
- Steuerung einzelner Gain-Kanäle
- Unterstützt **flüssige Lautstärkefahrten**
- Statusabfrage bewusst gedrosselt (kein Sekundentakt)

### Bose Module SourceSelector
- Umschaltung von Quellen / Presets
- Schnelle Statusaktualisierung **nur nach Änderung** (Burst-Polling)

### Bose Module Matrix
- Steuerung eines **Matrix-Mixer**-Moduls (bis 32 × 32)
- Pro Crosspoint eine Schaltvariable `XP_<Ein>_<Aus>` (Ein/Aus)
- Optional Crosspoint-Pegel `XPL_<Ein>_<Aus>` (-60.5 … 0.0 dB)
- Optional Ein-/Ausgangs-Mutes `InMute_<n>` / `OutMute_<n>` (true = Ton ein, wie beim Gain-Modul)
- Ein-/Ausgangsnamen kommagetrennt hinterlegbar, sie bilden die Variablennamen
- Status in Echtzeit über SUB-Abos, Fallback-Poll alle 5 Minuten
- Für Skripte, z. B. Trennwand-Logik:
  - `BOSE_SetCrosspoint($id, $eingang, $ausgang, true|false)`
  - `BOSE_SetCrosspointLevel($id, $eingang, $ausgang, -10.0)`
  - `BOSE_SetInputActive($id, $eingang, true|false)` / `BOSE_SetOutputActive($id, $ausgang, true|false)`
- Adressierung laut Protokoll: Index 2 = (Eingang - 1) × Anzahl Ausgänge + Ausgang.
  Weicht ein Gerät davon ab, lässt sich die Schrittweite unter „Erweitert“ festlegen.
- Hinweis: Jeder Crosspoint ist ein eigenes Abo. Große Matrizen (z. B. 5 × 20 = 100 Abos)
  funktionieren, Pegel-Variablen aber nur aktivieren, wenn sie gebraucht werden.

---

## Installation

1. IP-Symcon → **Modulverwaltung → Repositories**
2. Repository hinzufügen:
3. Module installieren
4. Instanzen anlegen (Device → Gain / SourceSelector)

### Hinweis SymBox (Caching)
Bei Problemen nach Updates:
1. Repository entfernen
2. SymBox neu starten
3. Repository erneut hinzufügen

---

## Netzwerk & Port-Konfiguration

- Die Verbindung erfolgt über eine **ClientSocket-Instanz**
- **IP-Adresse und Port bleiben frei konfigurierbar**
- Hintergrund:
- Der Port kann am Bose-Gerät geändert werden
- Während der Programmierung über die Bose-Software ist der Port
 temporär **geschlossen**

Das Modul erkennt diesen Zustand und vermeidet aggressive Reconnect-Schleifen.

---

## Polling-Strategie (Performance-relevant)

Die Statusabfrage ist **bewusst nicht sekündlich**, um die SymBox zu entlasten.

### Aktuelle Polling-Intervalle

- **Device**: alle **30 Sekunden**
- **Sources**: alle **30 Sekunden**
- zusätzlich **Burst-Polling (500 ms für 3 Sekunden)** nach einem Source-Wechsel
- **Gains**: alle **30 Sekunden**

Zusätzlich gilt:
- Variablen werden **nur bei tatsächlicher Wertänderung** geschrieben
- Dadurch bleibt die IP-Symcon-Spalte **„Aktualisiert“ ruhig**
- Keine unnötige Kernel-Last

---

## Gain-Fades / Lautstärken

- Gain-Änderungen werden **ohne künstliche Drosselung** gesendet
- Der Sendepfad ist vollständig vom Polling entkoppelt
- Bei aktiver Verbindung werden alle Werte **sofort** übertragen
- Bei kurzzeitigem Verbindungsverlust:
- letzter Sollwert wird gespeichert
- nach Reconnect erneut gesendet („last value wins“)

Damit bleibt die reale Lautstärke konsistent, auch bei Unterbrechungen.

---

## Verhalten bei Bose-Programmierung

Während der Programmierung über die Bose-Software:
- ist der TCP-Port des Geräts geschlossen
- das Modul geht in einen kontrollierten Holdoff-Zustand
- keine Reconnect-Stürme
- keine SymBox-Überlastung

Nach Abschluss der Programmierung nimmt das Modul automatisch
den Betrieb wieder auf.

---

## Anforderungen

- IP-Symcon **8.1**
- SymBox (empfohlen)
- Netzwerkverbindung zum Bose Audio Prozessor

---

## Autor

**FACE GmbH**

Dieses Modul wurde für den professionellen Einsatz in
Medien- und Gebäudetechnik entwickelt.
