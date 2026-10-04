<?php
/**
 * Les bornes du contrat, à un seul endroit.
 *
 * Chacune de ces valeurs est écrite dans `docs/api-connect-v1.md` côté Felar.
 * Les recopier ici plutôt que de les semer dans le code donne un seul endroit à
 * relire le jour où le contrat bouge — et un seul endroit à corriger.
 *
 * Les dépasser n'est jamais une bonne idée : le serveur refuse, mais il refuse
 * *la ligne*, et le marchand se retrouve avec un catalogue à trous qu'il faut
 * reprendre à la main. Mieux vaut couper ici, en le disant dans le rapport.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Contrat {

	/** L'adresse de production. Modifiable dans les réglages pour un tunnel de recette. */
	const BASE_DEFAUT = 'https://api.felar-crm.com';

	const CHEMIN = '/api/public/connect/v1';

	/** Toujours dans un en-tête : une clé en paramètre finit dans les journaux. */
	const ENTETE_CLE = 'X-Felar-Key';

	/** Cent lignes au plus par appel. Au-delà, `400 BATCH_TOO_LARGE`. */
	const LIGNES_PAR_APPEL = 100;

	/** Le flux de stock : 1 à 500 par page, 200 par défaut. */
	const FLUX_PAR_PAGE = 200;

	/**
	 * L'intervalle entre deux lectures du stock, en secondes.
	 *
	 * Le contrat dit cinq à quinze minutes, et explique pourquoi descendre plus bas
	 * ne sert à rien : ce n'est pas l'affichage qui protège de la survente, c'est le
	 * refus à la commande. Interroger toutes les trente secondes ne rendrait pas le
	 * stock plus juste, seulement le débit plus lourd.
	 */
	const FLUX_INTERVALLE = 600;

	/**
	 * Pages lues au plus en un passage.
	 *
	 * Deux mille articles par réveil : au-delà, on rend la main et on reprogramme,
	 * pour ne pas tenir une tâche de fond — et la base — pendant plusieurs minutes.
	 */
	const FLUX_PAGES_PAR_PASSAGE = 10;

	const EXTID_MAX = 64;
	const REFERENCE_MAX = 64;
	const INTITULE_MAX = 255;
	const DESCRIPTION_MAX = 20000;
	const IMAGE_MAX = 1000;

	/** Trois axes : au-delà, la grille n'est plus représentable à l'écran de Felar. */
	const AXES_MAX = 3;
	const VALEURS_PAR_AXE_MAX = 50;

	/**
	 * Deux cents déclinaisons vivantes par modèle.
	 *
	 * Un produit variable plus gros que ça est refusé plus bas dans Felar, après
	 * avoir traversé la validation : on l'arrête donc ici, et le rapport le nomme.
	 */
	const DECLINAISONS_PAR_MODELE_MAX = 200;

	const NIVEAUX_CATEGORIE_MAX = 4;

	const CHAMPS_MAX = 30;
	const CHAMP_NOM_MAX = 80;
	const CHAMP_VALEUR_MAX = 255;

	const OPTION_NOM_MAX = 64;
	const OPTION_VALEUR_MAX = 64;

	/** Commun à la fiche mère. */
	const PORTEE_MODELE = 'MODEL';

	/** Propre à une déclinaison. */
	const PORTEE_DECLINAISON = 'VARIANT';

	/** Les préfixes de clé, qui disent aussi le mode. */
	const PREFIXE_LIVE = 'ck_live_';
	const PREFIXE_TEST = 'ck_test_';

	/**
	 * Coupe un texte sans casser un caractère accentué en deux.
	 *
	 * `substr` compte des octets : sur « Bazin brodé », couper à la mauvaise
	 * position rend une chaîne que le serveur refusera comme non-UTF-8. Les
	 * bornes du contrat, elles, comptent des caractères.
	 *
	 * @param string $texte Le texte d'origine.
	 * @param int    $max   Le nombre de caractères permis.
	 * @return string
	 */
	public static function couper( $texte, $max ) {
		$texte = trim( (string) $texte );
		if ( '' === $texte ) {
			return '';
		}
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $texte, 'UTF-8' ) > $max
				? trim( mb_substr( $texte, 0, $max, 'UTF-8' ) )
				: $texte;
		}
		return strlen( $texte ) > $max ? trim( substr( $texte, 0, $max ) ) : $texte;
	}

	/** Vrai si la longueur dépasse la borne, avant coupe. */
	public static function trop_long( $texte, $max ) {
		$texte = trim( (string) $texte );
		$taille = function_exists( 'mb_strlen' ) ? mb_strlen( $texte, 'UTF-8' ) : strlen( $texte );
		return $taille > $max;
	}

	/**
	 * Vrai si on peut envoyer la clé à cette adresse.
	 *
	 * <h3>Pourquoi HTTPS, et pourquoi une exception</h3>
	 * La clé vaut un mot de passe : l'envoyer en clair sur un réseau la donne à
	 * qui écoute. Le HTTPS est donc la règle.
	 *
	 * L'exception est une adresse de <b>bouclage</b> — `localhost`, `127.0.0.1`,
	 * `[::1]`, un nom en `.localhost` ou `.test`. Là, rien ne sort de la machine :
	 * il n'y a pas de réseau à écouter. Et cette exception a une raison concrète,
	 * pas un confort de développeur : un intégrateur qui recette son branchement
	 * contre un Felar local n'a aucun moyen de lui donner un certificat public, et
	 * lui refuser cette adresse le pousserait à exposer son serveur de
	 * développement sur Internet — ce qui serait bien plus dangereux que ce qu'on
	 * cherchait à empêcher.
	 *
	 * @param string $adresse L'adresse saisie.
	 * @return bool
	 */
	public static function adresse_acceptable( $adresse ) {
		$adresse = trim( (string) $adresse );
		if ( 0 === stripos( $adresse, 'https://' ) ) {
			return true;
		}
		if ( 0 !== stripos( $adresse, 'http://' ) ) {
			return false;
		}

		$hote = parse_url( $adresse, PHP_URL_HOST );
		if ( ! is_string( $hote ) || '' === $hote ) {
			return false;
		}
		$hote = strtolower( trim( $hote, '[]' ) );

		return in_array( $hote, array( 'localhost', '127.0.0.1', '::1' ), true )
			|| (bool) preg_match( '/\.(localhost|test)$/', $hote );
	}

	/** Vrai si l'adresse est une adresse de bouclage, pour le dire à l'écran. */
	public static function adresse_locale( $adresse ) {
		return self::adresse_acceptable( $adresse )
			&& 0 !== stripos( trim( (string) $adresse ), 'https://' );
	}

	/** Le mode qu'annonce une clé, sans appeler le serveur. */
	public static function mode_de_la_cle( $cle ) {
		$cle = trim( (string) $cle );
		if ( 0 === strpos( $cle, self::PREFIXE_TEST ) ) {
			return 'test';
		}
		if ( 0 === strpos( $cle, self::PREFIXE_LIVE ) ) {
			return 'live';
		}
		return 'inconnu';
	}

	private function __construct() {
	}
}
