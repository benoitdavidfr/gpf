<?php
/** Définit la classe Zoom qui regroupe l'intelligence autour du tuilage et des niveaux de zoom */

/** classe regroupant l'intelligence autour du tuilage et des niveaux de zoom */
class Zoom {
  /**
   * Size0 est la circumférence de la Terre en mètres utilisée dans la projection WebMercator
   *
   * correspond à 2 * PI * a où a = 6 378 137.0 est le demi-axe majeur de l'ellipsoide WGS 84
   * Size0 est le côté du carré contenant les points en coordonnées WebMercator.
   * C'est aussi la taille en mètres terrain de la tuile (0,0,0) pour XYZ ou TMS.
   */
  const Size0 = 20037508.3427892476320267 * 2;

  /** Résolution standard d'un écran en mètres définie par WMS (document OGC® 06-042 du 15/3/2006).
   *
   * Cad la taille d'un pixel sur la carte, vaut 0,28 mm soit 2.8e-4 mètres. */
  const STD_RESOLUTION_IN_METERS = 2.8e-4;
  
  /** Conversion d'un dénominateur d'échelle en niveau de zoom.
   *
   * ($scaleDen * self::STD_RESOLUTION) donne la taille d'un pixel sur le terrain
   * ($scaleDen * self::STD_RESOLUTION * 256) donne la taille sur le terrain d'une tuile de largeur 256 pixels.
   * Au niveau 0 par définition la tuile a pour largeur Zoom::Size0.
   */
  static function zoomFromScaleDen(float $scaleDen): float {
    return log(Zoom::Size0 / ($scaleDen * self::STD_RESOLUTION_IN_METERS * 256), 2);
  }
  
  /** fonction inverse de calcul du dénominateur d'échelle à partir du niveau de zoom */
  static function scaleDenFromZoom(float $zoom): float {
    return Zoom::Size0 / (2 ** $zoom * self::STD_RESOLUTION_IN_METERS * 256);
  }
};
