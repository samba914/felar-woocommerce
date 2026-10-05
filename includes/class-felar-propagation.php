<?php
/**
 * Ce que Felar demande de corriger sur la boutique, et que le marchand a accepté.
 *
 * <h2>Rien n'arrive ici sans son accord</h2>
 * L'intitulé, la description et le prix appartiennent à la boutique : c'est la
 * page que ses clients lisent et que Google indexe. Felar ne pousse donc jamais
 * de lui-même — il met de côté, demande, et seules les modifications
 * <b>acceptées</b> descendent jusqu'ici.
 *
 * <h2>L'accusé de réception porte la valeur RÉELLEMENT posée</h2>
 * C'est ce qui coupe la boucle d'écho, et ce n'est pas une politesse. Une
 * boutique arrondit un prix — WooCommerce affiche deux décimales là où Felar en
 * tient quatre, et convertit en hors taxe si le marchand saisit ainsi. Si Felar
 * retenait la valeur qu'il a demandée plutôt que celle qui est affichée, sa
 * relecture suivante du catalogue verrait un écart, croirait à une modification
 * du site, et reproposerait la même propagation. Indéfiniment.
 *
 * On relit donc le prix tel que la boutique l'affiche après écriture, et c'est
 * lui qu'on renvoie.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Propagation {

	const OPTION = 'felar_connect_propagation';

	/** Cent modifications par passage : au-delà, le passage suivant finira. */
	const PAR_PASSAGE = 100;

	/** @var Felar_Reglages */
	private $reglages;

	public function __construct( Felar_Reglages $reglages ) {
		$this->reglages = $reglages;
	}

	/**
	 * Un passage : demande, applique, accuse réception.
	 *
	 * @return array Ce que l'écran affiche.
	 */
	public function passer() {
		if ( ! $this->reglages->branchee() ) {
			return $this->etat();
		}

		$client   = $this->reglages->client();
		$resultat = $client->lire_les_modifications( self::PAR_PASSAGE );

		if ( 'ok' !== $resultat['issue'] ) {
			return $this->clore( 'erreur', $resultat['message'], 0, 0, array() );
		}

		$attendues = is_array( $resultat['donnees'] ) ? $resultat['donnees'] : array();
		if ( empty( $attendues ) ) {
			return $this->clore( 'ok', '', 0, 0, array() );
		}

		$accuses = array();
		$posees  = 0;
		$refus   = array();

		foreach ( $attendues as $modification ) {
			$verdict = $this->appliquer( $modification );

			if ( null === $verdict['erreur'] ) {
				$posees++;
				$accuses[] = array(
					'id'           => $modification['id'],
					'appliedValue' => $verdict['applique'],
				);
				continue;
			}

			// La modification reste à porter côté Felar : une page supprimée ou un
			// produit disparu ne doit pas faire disparaître la décision du marchand.
			$refus[]   = array(
				'produit' => isset( $modification['label'] ) ? $modification['label'] : $modification['externalId'],
				'message' => $verdict['erreur'],
			);
			$accuses[] = array(
				'id'    => $modification['id'],
				'error' => Felar_Contrat::couper( $verdict['erreur'], 255 ),
			);
		}

		$retour = $client->accuser_les_modifications( $accuses );
		if ( 'ok' !== $retour['issue'] ) {
			// Sans accusé, Felar redemandera les mêmes au passage suivant. C'est
			// voulu : réappliquer la même valeur ne change rien, alors que perdre
			// l'accusé perdrait la trace de ce qui a été fait.
			return $this->clore( 'erreur', $retour['message'], $posees, count( $refus ), $refus );
		}

		return $this->clore( 'ok', '', $posees, count( $refus ), $refus );
	}

	/**
	 * Applique une modification à un produit de la boutique.
	 *
	 * @param array $modification Une entrée rendue par Felar.
	 * @return array{applique: ?string, erreur: ?string}
	 */
	private function appliquer( array $modification ) {
		$identifiant = isset( $modification['externalId'] ) ? (int) $modification['externalId'] : 0;
		$produit     = $identifiant > 0 ? wc_get_product( $identifiant ) : null;

		if ( ! $produit ) {
			return $this->echec( 'Cet article n\'existe plus dans WooCommerce.' );
		}

		$valeur = isset( $modification['newValue'] ) ? (string) $modification['newValue'] : '';
		$champ  = isset( $modification['field'] ) ? (string) $modification['field'] : '';

		switch ( $champ ) {
			case 'LABEL':
				$produit->set_name( Felar_Contrat::couper( $valeur, 255 ) );
				$produit->save();
				return $this->pose( $produit->get_name() );

			case 'DESCRIPTION':
				$produit->set_description( $valeur );
				$produit->save();
				return $this->pose( $produit->get_description() );

			case 'PRICE':
				return $this->poser_le_prix( $produit, $valeur );

			default:
				// Un champ que cette version ne sait pas poser. Le dire vaut mieux
				// que de l'ignorer : Felar le redemanderait indéfiniment.
				return $this->echec( 'Cette extension ne sait pas encore modifier « ' . $champ .' ».' );
		}
	}

	/**
	 * Écrit le prix, dans la convention de la boutique.
	 *
	 * <h3>Felar envoie un prix toutes taxes</h3>
	 * Si le marchand saisit ses prix hors taxe, l'écrire tel quel gonflerait son
	 * catalogue de la TVA d'un coup. On convertit donc, avec le taux de la fiche —
	 * et on relit ensuite ce que la boutique affiche réellement, parce que c'est
	 * cette valeur-là qui coupera l'écho.
	 */
	private function poser_le_prix( $produit, $valeur ) {
		if ( '' === trim( $valeur ) || ! is_numeric( trim( $valeur ) ) ) {
			return $this->echec( 'Felar a envoyé un prix illisible.' );
		}

		$decimales = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$a_ecrire  = self::prix_a_ecrire(
			$valeur,
			wc_prices_include_tax(),
			$this->taux( $produit )
		);
		$produit->set_regular_price( wc_format_decimal( $a_ecrire, $decimales ) );

		// Un prix promotionnel plus élevé que le prix de base n'aurait aucun sens,
		// et WooCommerce continuerait d'afficher l'ancienne promotion.
		$promo = $produit->get_sale_price();
		if ( '' !== $promo && (float) $promo > (float) $produit->get_regular_price() ) {
			$produit->set_sale_price( '' );
		}

		$produit->save();

		// Ce que la boutique affiche vraiment, taxes comprises : la seule valeur
		// que Felar puisse comparer à la sienne sans se tromper.
		$affiche = function_exists( 'wc_get_price_including_tax' )
			? wc_get_price_including_tax( $produit )
			: $produit->get_price();

		return $this->pose( wc_format_decimal( $affiche, $decimales ) );
	}

	/**
	 * Le prix à écrire dans WooCommerce, à partir du prix toutes taxes de Felar.
	 *
	 * <h3>Pourquoi cette conversion n'est pas optionnelle</h3>
	 * Felar tient un prix toutes taxes. Un marchand qui saisit ses prix hors taxe
	 * et à qui on écrirait la valeur telle quelle verrait son catalogue entier
	 * gonfler de la TVA d'un coup — et il ne le découvrirait qu'à la première
	 * commande, ou pire, par un client.
	 *
	 * Fonction pure, et séparée pour cela : c'est le seul calcul de ce fichier
	 * qu'on peut éprouver sans WordPress, et c'est aussi le seul qui coûte cher.
	 *
	 * @param mixed $ttc        Le prix toutes taxes envoyé par Felar.
	 * @param bool  $prix_ttc   Vrai si la boutique saisit ses prix taxe comprise.
	 * @param float $taux       Le taux de la fiche, en pourcentage.
	 * @return float
	 */
	public static function prix_a_ecrire( $ttc, $prix_ttc, $taux ) {
		$montant = (float) $ttc;

		if ( $prix_ttc || $taux <= 0 ) {
			return $montant;
		}

		return $montant / ( 1 + ( (float) $taux / 100 ) );
	}

	/** Le taux de taxe de la fiche, pour convertir un prix toutes taxes. */
	private function taux( $produit ) {
		if ( ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() ) {
			return 0.0;
		}
		$table = Felar_Taxes::table_woocommerce();
		$classe = (string) $produit->get_tax_class();
		return isset( $table[ $classe ] ) ? (float) $table[ $classe ] : 0.0;
	}

	private function pose( $valeur ) {
		return array(
			'applique' => (string) $valeur,
			'erreur'   => null,
		);
	}

	private function echec( $message ) {
		return array(
			'applique' => null,
			'erreur'   => $message,
		);
	}

	/** Clôt le passage et retient ce qu'il a fait. */
	private function clore( $verdict, $message, $posees, $echecs, array $refus ) {
		$etat = array(
			'verdict' => $verdict,
			'message' => $message,
			'posees'  => (int) $posees,
			'echecs'  => (int) $echecs,
			'refus'   => array_slice( $refus, 0, 20 ),
			'dernier' => time(),
		);
		update_option( self::OPTION, $etat, false );

		if ( $posees > 0 ) {
			Felar_Journal::noter( $posees . ' modification(s) portée(s) depuis Felar.' );
		}
		if ( 'erreur' === $verdict ) {
			Felar_Journal::noter( 'Propagation : ' . $message, 'error' );
		}

		return $etat;
	}

	/** Ce que l'écran affiche. */
	public function etat() {
		$defauts = array(
			'verdict' => '',
			'message' => '',
			'posees'  => 0,
			'echecs'  => 0,
			'refus'   => array(),
			'dernier' => 0,
		);
		$etat = get_option( self::OPTION, array() );
		return array_merge( $defauts, is_array( $etat ) ? $etat : array() );
	}

	/** Oublie tout, à la désinstallation. */
	public static function oublier() {
		delete_option( self::OPTION );
	}
}
