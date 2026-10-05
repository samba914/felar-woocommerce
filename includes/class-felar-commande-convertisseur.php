<?php
/**
 * Une commande WooCommerce, traduite pour Felar.
 *
 * Même frontière que pour le catalogue : cette classe ne reçoit que des tableaux,
 * et c'est ce qui permet d'éprouver les calculs d'argent — ceux qui décident si le
 * marchand facture juste — sans installer WordPress.
 *
 * <h2>Tout part HORS TAXE, et ce n'est pas un détail</h2>
 * WooCommerce range les totaux de ligne d'une commande <b>toujours hors taxe</b>,
 * quel que soit le réglage « prix saisis taxe comprise » de la boutique. Reprendre
 * ce réglage ici serait donc une erreur : il parle de la saisie du catalogue, pas
 * de ce qui est enregistré dans la commande. On envoie du HT et on le déclare,
 * point final — un doute de moins sur le seul chiffre qui ne se rattrape pas.
 *
 * <h2>Les prix sont nets de toute remise</h2>
 * `get_total()` d'une ligne est le montant <b>après</b> remises, y compris un code
 * promotionnel portant sur la commande entière ; `get_subtotal()` est celui
 * d'avant. C'est le premier qu'il faut, parce que Felar ne répartit aucune remise
 * globale : il faudrait deviner sur quelles lignes et à quel taux de taxe, et le
 * total du marchand ne tomberait plus juste.
 *
 * <h2>Le taux de taxe se déduit de la commande, pas du catalogue</h2>
 * Celui du produit a pu changer depuis. Celui qui compte est celui qui a été
 * facturé : c'est le rapport entre la taxe de la ligne et son montant.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Commande_Convertisseur {

	/** @var string Devise du compte Felar. */
	private $devise;

	public function __construct( $devise ) {
		$this->devise = strtoupper( (string) $devise );
	}

	/**
	 * Convertit une commande brute.
	 *
	 * @param array $brut Voir {@see Felar_Commande_Lecteur} pour la forme.
	 * @return array{payload: ?array, refus: ?string, notes: array}
	 */
	public function convertir( array $brut ) {
		$notes = array();

		$lignes = $this->lignes( $brut, $notes );
		if ( empty( $lignes ) ) {
			// Une commande sans ligne vendable n'a rien à enregistrer. Cela arrive sur
			// une commande qui ne porte que des frais, ou qu'un brouillon oublié.
			return $this->refus( 'SANS_LIGNE' );
		}

		$client = $this->client( $brut, $notes );
		if ( null === $client ) {
			return $this->refus( 'SANS_CLIENT' );
		}

		$payload = array(
			'source'     => 'woocommerce',
			'externalId' => Felar_Contrat::couper( (string) $brut['id'], Felar_Contrat::EXTID_MAX ),
			'customer'   => $client,
			'lines'      => $lignes,
			'total'      => Felar_Contrat::montant( isset( $brut['total'] ) ? $brut['total'] : 0, $this->devise ),
		);

		$numero = Felar_Contrat::couper( isset( $brut['numero'] ) ? $brut['numero'] : '', 64 );
		if ( '' !== $numero ) {
			$payload['externalReference'] = $numero;
		}

		$date = $this->instant( isset( $brut['date'] ) ? $brut['date'] : '' );
		if ( null !== $date ) {
			$payload['placedAt'] = $date;
		}

		$livraison = $this->livraison( $brut, $notes );
		if ( null !== $livraison['entete'] ) {
			$payload['shipping'] = $livraison['entete'];
		}
		if ( null !== $livraison['ligne'] ) {
			// Un transport taxé part en ligne ordinaire : `shipping.amount` est
			// enregistré sans taxe par Felar, et le total ne tomberait plus juste.
			$payload['lines'][] = $livraison['ligne'];
		}

		$payload['payment'] = $this->paiement( $brut );

		$note = Felar_Contrat::couper( isset( $brut['note'] ) ? $brut['note'] : '', 500 );
		if ( '' !== $note ) {
			$payload['note'] = $note;
		}

		$adresse = Felar_Contrat::couper( isset( $brut['admin_url'] ) ? $brut['admin_url'] : '', 500 );
		if ( '' !== $adresse ) {
			// Felar en fait un lien vers la commande chez le marchand : c'est ce qui
			// lui évite de chercher l'une depuis l'autre.
			$payload['adminUrl'] = $adresse;
		}

		return array(
			'payload' => $payload,
			'refus'   => null,
			'notes'   => array_values( array_unique( $notes ) ),
		);
	}

	/**
	 * Les lignes vendables.
	 *
	 * Une ligne à quantité nulle ou sans intitulé est écartée : elle ferait refuser
	 * la commande entière par la validation de Felar, et une vente ne doit pas se
	 * perdre pour une ligne vide.
	 */
	private function lignes( array $brut, array &$notes ) {
		$sortie = array();

		foreach ( (array) ( isset( $brut['lignes'] ) ? $brut['lignes'] : array() ) as $ligne ) {
			$quantite = isset( $ligne['quantite'] ) ? (float) $ligne['quantite'] : 0;
			$intitule = Felar_Contrat::couper( isset( $ligne['intitule'] ) ? $ligne['intitule'] : '', Felar_Contrat::INTITULE_MAX );

			if ( $quantite <= 0 || '' === $intitule ) {
				$notes[] = 'LIGNE_VIDE';
				continue;
			}

			$total = isset( $ligne['total'] ) ? (float) $ligne['total'] : 0.0;
			$taxe  = isset( $ligne['taxe'] ) ? (float) $ligne['taxe'] : 0.0;

			$entree = array(
				'label'            => $intitule,
				'quantity'         => $this->quantite( $quantite ),
				'unitPrice'        => Felar_Contrat::montant( $total / $quantite, $this->devise ),
				'priceIncludesTax' => false,
				'taxRate'          => $this->taux( $total, $taxe ),
			);

			$externe = Felar_Contrat::couper( isset( $ligne['produit'] ) ? (string) $ligne['produit'] : '', Felar_Contrat::EXTID_MAX );
			if ( '' !== $externe && '0' !== $externe ) {
				$entree['externalId'] = $externe;
			} else {
				// Felar gardera la ligne hors catalogue, avec son intitulé et son
				// prix. On ne perd pas une vente parce qu'un article a été supprimé
				// de la boutique depuis.
				$notes[] = 'LIGNE_HORS_CATALOGUE';
			}

			$reference = Felar_Contrat::couper( isset( $ligne['reference'] ) ? $ligne['reference'] : '', Felar_Contrat::REFERENCE_MAX );
			if ( '' !== $reference ) {
				$entree['reference'] = $reference;
			}

			$sortie[] = $entree;
		}

		return $sortie;
	}

	/**
	 * Le taux facturé sur une ligne, déduit de la commande elle-même.
	 *
	 * Un montant nul ne permet aucune déduction — un article offert, par exemple :
	 * on envoie zéro, qui est la vérité comptable de cette ligne.
	 */
	private function taux( $total, $taxe ) {
		if ( $total <= 0 || $taxe <= 0 ) {
			return 0.0;
		}
		return Felar_Taxes::borner( round( ( $taxe / $total ) * 100, 2 ) );
	}

	/**
	 * La quantité, décimale si besoin.
	 *
	 * Felar accepte les quantités décimales depuis le pack Services — trois heures
	 * et demie de prestation existent. Les tronquer à l'entier facturerait faux.
	 */
	private function quantite( $quantite ) {
		$arrondie = round( (float) $quantite, 3 );
		return abs( $arrondie - round( $arrondie ) ) < 0.0005 ? (int) round( $arrondie ) : $arrondie;
	}

	/**
	 * Le client, ou null s'il n'a même pas de nom.
	 *
	 * Felar exige un prénom ou un nom. Une commande anonyme n'existe pas sur une
	 * boutique : il y a toujours au moins un nom de facturation.
	 */
	private function client( array $brut, array &$notes ) {
		$client  = isset( $brut['client'] ) ? (array) $brut['client'] : array();
		$prenom  = Felar_Contrat::couper( isset( $client['prenom'] ) ? $client['prenom'] : '', 80 );
		$nom     = Felar_Contrat::couper( isset( $client['nom'] ) ? $client['nom'] : '', 80 );

		if ( '' === $prenom && '' === $nom ) {
			return null;
		}
		if ( '' === $prenom ) {
			// Felar veut le prénom rempli en premier : un nom seul y entre comme
			// prénom plutôt que de produire une fiche sans identité lisible.
			$prenom = $nom;
			$nom    = '';
		}

		$sortie = array( 'firstName' => $prenom );
		if ( '' !== $nom ) {
			$sortie['lastName'] = $nom;
		}

		$telephone = Felar_Contrat::couper( isset( $client['telephone'] ) ? $client['telephone'] : '', 30 );
		if ( '' !== $telephone ) {
			$sortie['phone'] = $telephone;
		}

		$courriel = trim( (string) ( isset( $client['email'] ) ? $client['email'] : '' ) );
		if ( '' !== $courriel && false !== strpos( $courriel, '@' ) ) {
			$sortie['email'] = Felar_Contrat::couper( $courriel, 180 );
		}

		if ( '' === $telephone && ! isset( $sortie['email'] ) ) {
			// Sans téléphone ni courriel, Felar ne peut rapprocher ce client d'aucune
			// fiche : il en créera une nouvelle à chaque commande.
			$notes[] = 'CLIENT_SANS_CONTACT';
		}

		$adresse = Felar_Contrat::couper( isset( $client['adresse'] ) ? $client['adresse'] : '', 255 );
		if ( '' !== $adresse ) {
			$sortie['address'] = $adresse;
		}
		$complement = Felar_Contrat::couper( isset( $client['complement'] ) ? $client['complement'] : '', 255 );
		if ( '' !== $complement ) {
			$sortie['addressDetails'] = $complement;
		}

		return $sortie;
	}

	/**
	 * Les frais de livraison, enregistrés tels quels et sans taxe.
	 *
	 * La plupart des boutiques ne taxent pas le transport, et deviner un taux ici
	 * ferait un total faux. Un marchand qui le taxe l'envoie en ligne ordinaire.
	 */
	private function livraison( array $brut, array &$notes ) {
		$livraison = isset( $brut['livraison'] ) ? (array) $brut['livraison'] : array();
		$montant   = isset( $livraison['montant'] ) ? (float) $livraison['montant'] : 0.0;
		$taxe      = isset( $livraison['taxe'] ) ? (float) $livraison['taxe'] : 0.0;
		$methode   = strtoupper( trim( (string) ( isset( $livraison['methode'] ) ? $livraison['methode'] : '' ) ) );
		$intitule  = Felar_Contrat::couper( isset( $livraison['intitule'] ) ? $livraison['intitule'] : '', 120 );

		$rien = array(
			'entete' => null,
			'ligne'  => null,
		);

		if ( $montant <= 0 && '' === $methode ) {
			return $rien;
		}

		$entete = array();
		if ( '' !== $intitule ) {
			$entete['label'] = $intitule;
		}
		if ( in_array( $methode, array( 'DELIVERY', 'PICKUP' ), true ) ) {
			$entete['method'] = $methode;
		}

		if ( $taxe <= 0 ) {
			// Le cas ordinaire : Felar enregistre le port tel quel, sans taxe.
			$entete['amount'] = Felar_Contrat::montant( $montant, $this->devise );
			return array(
				'entete' => $entete,
				'ligne'  => null,
			);
		}

		// Le marchand taxe son transport. Comme `shipping.amount` est enregistré
		// **sans** taxe, le laisser là ferait un total inférieur au sien — et
		// au-delà de 5 % d'écart, Felar refuserait la commande entière. Le contrat
		// prescrit donc de l'envoyer en ligne ordinaire, qui porte sa taxe comme les
		// autres. Trouvé sur une vraie commande, pas en relisant le contrat.
		$notes[] = 'LIVRAISON_TAXEE';

		return array(
			'entete' => empty( $entete ) ? null : $entete,
			'ligne'  => array(
				'label'            => '' !== $intitule ? $intitule : 'Livraison',
				'quantity'         => 1,
				'unitPrice'        => Felar_Contrat::montant( $montant, $this->devise ),
				'priceIncludesTax' => false,
				'taxRate'          => $this->taux( $montant, $taxe ),
			),
		);
	}

	/**
	 * Le règlement.
	 *
	 * La commande part <b>dès qu'elle existe</b>, payée ou non : attendre le
	 * paiement laisserait passer les commandes à régler à la livraison, et Felar ne
	 * réserverait le stock que trop tard.
	 */
	private function paiement( array $brut ) {
		$paiement = isset( $brut['paiement'] ) ? (array) $brut['paiement'] : array();
		$statut   = strtoupper( trim( (string) ( isset( $paiement['statut'] ) ? $paiement['statut'] : '' ) ) );

		$sortie = array(
			'status' => in_array( $statut, array( 'PAID', 'UNPAID', 'PARTIAL' ), true ) ? $statut : 'UNPAID',
		);

		$methode = Felar_Contrat::couper( isset( $paiement['methode'] ) ? $paiement['methode'] : '', 20 );
		if ( '' !== $methode ) {
			// Un mode inconnu de Felar est conservé tel quel pour l'affichage : il
			// n'a pas à connaître les passerelles de paiement du monde entier.
			$sortie['method'] = $methode;
		}

		$quand = $this->instant( isset( $paiement['paye_le'] ) ? $paiement['paye_le'] : '' );
		if ( null !== $quand ) {
			$sortie['paidAt'] = $quand;
		}

		if ( isset( $paiement['montant'] ) && is_numeric( $paiement['montant'] ) && (float) $paiement['montant'] > 0 ) {
			$sortie['amount'] = Felar_Contrat::montant( $paiement['montant'], $this->devise );
		}

		return $sortie;
	}

	/** Un instant ISO 8601 en UTC, ou null. */
	private function instant( $valeur ) {
		$valeur = trim( (string) $valeur );
		if ( '' === $valeur ) {
			return null;
		}
		$horodatage = strtotime( $valeur );
		return false === $horodatage ? null : gmdate( 'Y-m-d\TH:i:s\Z', $horodatage );
	}

	private function refus( $code ) {
		return array(
			'payload' => null,
			'refus'   => $code,
			'notes'   => array(),
		);
	}

	/** Les phrases du rapport. */
	public static function phrases() {
		return array(
			'SANS_LIGNE'           => 'Cette commande ne porte aucune ligne vendable : il n\'y a rien à enregistrer dans Felar.',
			'SANS_CLIENT'          => 'Cette commande n\'a ni prénom ni nom de facturation. Felar en a besoin pour rattacher la vente à quelqu\'un.',
			'LIGNE_VIDE'           => 'Une ligne sans intitulé ou à quantité nulle a été laissée de côté ; le reste de la commande est parti.',
			'LIGNE_HORS_CATALOGUE' => 'Un article de cette commande n\'a pas d\'équivalent dans Felar : la ligne y est conservée hors catalogue, avec son intitulé et son prix. On ne perd pas une vente parce qu\'un article a été supprimé depuis.',
			'LIVRAISON_TAXEE'      => 'Vos frais de livraison portent une taxe : ils partent comme une ligne de commande plutôt que comme un frais de port, pour que le total de Felar tombe juste.',
			'CLIENT_SANS_CONTACT'  => 'Ce client n\'a ni téléphone ni courriel : Felar ne peut le rapprocher d\'aucune fiche, et en créera une nouvelle.',
		);
	}

	/** La phrase d'un code, ou le code lui-même. */
	public static function phrase( $code ) {
		$phrases = self::phrases();
		return isset( $phrases[ $code ] ) ? $phrases[ $code ] : (string) $code;
	}
}
