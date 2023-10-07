# Test d'utilisation de la Géoplateforme
Le script view.php a pour objectif de consulter les couches des différents services WMS, WMTS et TMS de la Géoplateforme.
Pour cela il lit les capacités des services, liste leurs couches
et en cliquant sur le titre d'une couche construit une carte Leaflet affichant cette couche.

Le script pwms.php est un proxy WMS permettant de définir des couches à partir de celles de la géoplateforme.
Par exemple, une couches cartesIGN est définie pour visualiser les différentes cartes IGN en fonction de l'échelle.
