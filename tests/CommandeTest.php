<?php
/**
 * La traduction d'une commande WooCommerce.
 *
 * <h2>Ce que ces cas protègent</h2>
 * Les calculs d'argent, c'est-à-dire la seule partie qui, fausse, ne se rattrape
 * pas : un prix unitaire pris avant remise facture plus cher que le client n'a
 * payé, et un total qui s'écarte de plus de 5 % se fait refuser par Felar — ce qui
 * est précisément le garde-fou contre une boutique tenue en centimes.
 *
 * @package Felar_Connect
 */

use PHPUnit\Framework\TestCase;

class CommandeTest extends TestCase {

	private function convertisseur() {
		return new Felar_Commande_Convertisseur( 'XOF' );
	}

	/** Une commande complète et saine. */
	private function commande( array $surcharges = array() ) {
		return array_merge(
			array(
				'id'        => 5842,
				'numero'    => '#5842',
				'date'      => '2026-09-29T10:58:04+00:00',
				'client'    => array(
					'prenom'     => 'Fatou',
					'nom'        => 'Ndiaye',
					'telephone'  => '+221771234567',
					'email'      => 'fatou@example.sn',
					'adresse'    => 'Cité Keur Gorgui, villa 12',
					'complement' => 'Dakar',
				),
				'lignes'    => array(
					array(
						'produit'   => 1287,
						'reference' => 'BZ-SAC-01',
						'intitule'  => 'Sac bazin brodé',
						'quantite'  => 2,
						'total'     => '41525.42',
						'taxe'      => '7474.58',
					),
				),
				'livraison' => array(
					'intitule' => 'Livraison Dakar',
					'montant'  => '2000',
					'methode'  => 'DELIVERY',
				),
				'total'     => '51000',
				'paiement'  => array(
					'statut'  => 'PAID',
					'methode' => 'WAVE',
					'paye_le' => '2026-09-29T10:58:40+00:00',
					'montant' => '51000',
				),
				'note'      => 'Appeler avant de passer',
				'admin_url' => 'https://chez-fatou.sn/wp-admin/post.php?post=5842&action=edit',
			),
			$surcharges
		);
	}

	public function test_une_commande_complete_part_entiere() {
		$resultat = $this->convertisseur()->convertir( $this->commande() );
		$envoi    = $resultat['payload'];

		$this->assertNull( $resultat['refus'] );
		$this->assertSame( 'woocommerce', $envoi['source'] );
		$this->assertSame( '5842', $envoi['externalId'] );
		$this->assertSame( '#5842', $envoi['externalReference'] );
		$this->assertSame( '2026-09-29T10:58:04Z', $envoi['placedAt'] );
		$this->assertSame( 'Fatou', $envoi['customer']['firstName'] );
		$this->assertSame( '+221771234567', $envoi['customer']['phone'] );
		$this->assertSame( array( 'value' => '51000', 'currency' => 'XOF' ), $envoi['total'] );
		$this->assertSame( 'PAID', $envoi['payment']['status'] );
		$this->assertSame( 'WAVE', $envoi['payment']['method'] );
		$this->assertStringContainsString( 'post=5842', $envoi['adminUrl'] );
	}

	public function test_le_prix_unitaire_est_hors_taxe_et_net_de_remise() {
		// WooCommerce range les totaux de ligne hors taxe et APRÈS remises, quel que
		// soit le réglage « prix saisis taxe comprise ». C'est ce chiffre qu'il faut :
		// Felar ne répartit aucune remise globale.
		$ligne = $this->convertisseur()->convertir( $this->commande() )['payload']['lines'][0];

		$this->assertFalse( $ligne['priceIncludesTax'] );
		$this->assertSame( '20762.71', $ligne['unitPrice']['value'] );
		$this->assertSame( 2, $ligne['quantity'] );
	}

	public function test_le_taux_se_deduit_de_la_commande_et_non_du_catalogue() {
		// Celui du produit a pu changer depuis ; celui qui compte est celui qui a été
		// facturé. 7 474,58 sur 41 525,42 font bien 18 %.
		$ligne = $this->convertisseur()->convertir( $this->commande() )['payload']['lines'][0];

		$this->assertSame( 18.0, $ligne['taxRate'] );
	}

	public function test_une_ligne_sans_taxe_part_a_zero() {
		$commande = $this->commande();
		$commande['lignes'][0]['taxe'] = '0';

		$ligne = $this->convertisseur()->convertir( $commande )['payload']['lines'][0];

		$this->assertSame( 0.0, $ligne['taxRate'] );
	}

	public function test_un_article_offert_ne_fait_pas_exploser_le_taux() {
		// Diviser par un total nul donnerait l'infini, et la ligne serait refusée.
		$commande = $this->commande();
		$commande['lignes'][0]['total'] = '0';
		$commande['lignes'][0]['taxe']  = '0';

		$ligne = $this->convertisseur()->convertir( $commande )['payload']['lines'][0];

		$this->assertSame( 0.0, $ligne['taxRate'] );
		$this->assertSame( '0', $ligne['unitPrice']['value'] );
	}

	public function test_un_article_disparu_du_catalogue_ne_perd_pas_la_vente() {
		// La ligne part sans identifiant : Felar la conserve hors catalogue, avec son
		// intitulé et son prix.
		$commande = $this->commande();
		$commande['lignes'][0]['produit'] = 0;

		$resultat = $this->convertisseur()->convertir( $commande );

		$this->assertArrayNotHasKey( 'externalId', $resultat['payload']['lines'][0] );
		$this->assertSame( 'Sac bazin brodé', $resultat['payload']['lines'][0]['label'] );
		$this->assertContains( 'LIGNE_HORS_CATALOGUE', $resultat['notes'] );
	}

	public function test_une_ligne_vide_ne_fait_pas_tomber_la_commande() {
		$commande = $this->commande();
		$commande['lignes'][] = array(
			'produit'  => 99,
			'intitule' => '',
			'quantite' => 0,
			'total'    => '0',
			'taxe'     => '0',
		);

		$resultat = $this->convertisseur()->convertir( $commande );

		$this->assertCount( 1, $resultat['payload']['lines'] );
		$this->assertContains( 'LIGNE_VIDE', $resultat['notes'] );
	}

	public function test_une_commande_sans_aucune_ligne_vendable_est_refusee() {
		$resultat = $this->convertisseur()->convertir( $this->commande( array( 'lignes' => array() ) ) );

		$this->assertNull( $resultat['payload'] );
		$this->assertSame( 'SANS_LIGNE', $resultat['refus'] );
	}

	public function test_une_commande_sans_identite_est_refusee() {
		$commande = $this->commande();
		$commande['client']['prenom'] = '';
		$commande['client']['nom']    = '';

		$this->assertSame( 'SANS_CLIENT', $this->convertisseur()->convertir( $commande )['refus'] );
	}

	public function test_un_nom_seul_entre_comme_prenom() {
		// Felar veut le prénom rempli en premier : sinon la fiche client n'a pas
		// d'identité lisible à l'écran.
		$commande = $this->commande();
		$commande['client']['prenom'] = '';

		$client = $this->convertisseur()->convertir( $commande )['payload']['customer'];

		$this->assertSame( 'Ndiaye', $client['firstName'] );
		$this->assertArrayNotHasKey( 'lastName', $client );
	}

	public function test_un_client_sans_moyen_de_contact_est_signale() {
		// Felar ne pourra le rapprocher d'aucune fiche : il en créera une par
		// commande, et le marchand verra son fichier client se dédoubler.
		$commande = $this->commande();
		$commande['client']['telephone'] = '';
		$commande['client']['email']     = '';

		$resultat = $this->convertisseur()->convertir( $commande );

		$this->assertContains( 'CLIENT_SANS_CONTACT', $resultat['notes'] );
		$this->assertArrayNotHasKey( 'phone', $resultat['payload']['customer'] );
	}

	public function test_un_courriel_sans_arobase_nest_pas_envoye() {
		// Felar refuserait la commande entière sur une adresse invalide ; le reste de
		// la vente n'a pas à en pâtir.
		$commande = $this->commande();
		$commande['client']['email'] = 'pas-une-adresse';

		$client = $this->convertisseur()->convertir( $commande )['payload']['customer'];

		$this->assertArrayNotHasKey( 'email', $client );
	}

	public function test_le_retrait_en_magasin_se_distingue_de_la_livraison() {
		$commande = $this->commande();
		$commande['livraison'] = array(
			'intitule' => 'Retrait boutique',
			'montant'  => '0',
			'methode'  => 'PICKUP',
		);

		$livraison = $this->convertisseur()->convertir( $commande )['payload']['shipping'];

		$this->assertSame( 'PICKUP', $livraison['method'] );
		$this->assertSame( '0', $livraison['amount']['value'] );
	}

	public function test_une_commande_sans_frais_ni_methode_nenvoie_pas_de_livraison() {
		$commande = $this->commande();
		$commande['livraison'] = array( 'intitule' => '', 'montant' => '0', 'methode' => '' );

		$this->assertArrayNotHasKey( 'shipping', $this->convertisseur()->convertir( $commande )['payload'] );
	}

	public function test_une_commande_impayee_part_quand_meme() {
		// Attendre le règlement laisserait passer les commandes à payer à la
		// livraison, et Felar réserverait le stock trop tard.
		$commande = $this->commande();
		$commande['paiement'] = array(
			'statut'  => 'UNPAID',
			'methode' => 'COD',
			'paye_le' => '',
			'montant' => 0,
		);

		$paiement = $this->convertisseur()->convertir( $commande )['payload']['payment'];

		$this->assertSame( 'UNPAID', $paiement['status'] );
		$this->assertSame( 'COD', $paiement['method'] );
		$this->assertArrayNotHasKey( 'paidAt', $paiement );
		$this->assertArrayNotHasKey( 'amount', $paiement );
	}

	public function test_un_statut_de_paiement_inconnu_devient_impaye() {
		$commande = $this->commande();
		$commande['paiement']['statut'] = 'EN_ATTENTE_DE_VALIDATION';

		$this->assertSame( 'UNPAID', $this->convertisseur()->convertir( $commande )['payload']['payment']['status'] );
	}

	public function test_une_quantite_decimale_survit() {
		// Felar accepte les quantités décimales depuis le pack Services : trois
		// heures et demie de prestation existent, et les tronquer facturerait faux.
		$commande = $this->commande();
		$commande['lignes'][0]['quantite'] = 3.5;
		$commande['lignes'][0]['total']    = '35000';
		$commande['lignes'][0]['taxe']     = '0';

		$ligne = $this->convertisseur()->convertir( $commande )['payload']['lines'][0];

		$this->assertSame( 3.5, $ligne['quantity'] );
		$this->assertSame( '10000', $ligne['unitPrice']['value'] );
	}

	public function test_les_montants_ne_trainent_aucun_artefact_de_flottant() {
		// Un total sorti en 50999.999999 ferait basculer le garde-fou des 5 % de
		// Felar sur une commande parfaitement normale.
		$commande = $this->commande( array( 'total' => 51000.00 ) );

		$this->assertSame( '51000', $this->convertisseur()->convertir( $commande )['payload']['total']['value'] );
	}

	public function test_un_transport_taxe_part_en_ligne_et_non_en_frais_de_port() {
		// `shipping.amount` est enregistré SANS taxe par Felar : laisser le port là
		// ferait un total inférieur de la taxe, et au-delà de 5 % d'écart Felar
		// refuserait la commande entière. Trouvé sur une vraie commande.
		$commande = $this->commande();
		$commande['livraison']['taxe'] = '360';

		$resultat = $this->convertisseur()->convertir( $commande );
		$envoi    = $resultat['payload'];

		$this->assertArrayNotHasKey( 'amount', $envoi['shipping'] );
		$this->assertSame( 'DELIVERY', $envoi['shipping']['method'] );

		$port = end( $envoi['lines'] );
		$this->assertSame( 'Livraison Dakar', $port['label'] );
		$this->assertSame( '2000', $port['unitPrice']['value'] );
		$this->assertSame( 18.0, $port['taxRate'] );
		$this->assertContains( 'LIVRAISON_TAXEE', $resultat['notes'] );
	}

	public function test_un_transport_non_taxe_reste_un_frais_de_port() {
		$envoi = $this->convertisseur()->convertir( $this->commande() )['payload'];

		$this->assertSame( '2000', $envoi['shipping']['amount']['value'] );
		$this->assertCount( 1, $envoi['lines'] );
	}

	public function test_chaque_code_a_une_phrase() {
		foreach ( array_keys( Felar_Commande_Convertisseur::phrases() ) as $code ) {
			$this->assertNotSame( $code, Felar_Commande_Convertisseur::phrase( $code ) );
		}
	}
}
