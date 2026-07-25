# Plans comptables livrés

`veb_pme_2024_fr.csv` transpose le **Plan comptable suisse PME
(Mattle/Helbling/Pfaff), version officielle de référence du 12 août 2024**.
Source : Administration fédérale, document VEB,
<https://www.kmu.admin.ch/dam/fr/sd-web/ddOMnlBEN93Z/240812%20Schulkontenrahmen%20VEB%20-%20FR.pdf>.
Attribution : © veb.ch, Zürich.

La structure et les trois variantes de capitaux propres du document sont
conservées. À l'installation d'un dossier, une seule variante est sélectionnée.
Les types et sens normaux sont stockés explicitement : le premier chiffre du
numéro n'est jamais utilisé comme règle métier.

`association_2024_v1.csv` est un overlay propre au projet, versionné séparément.
Les comptes de projets et de fonds affectés sont activés selon les options
`projets` et `fonds_affectes`.
