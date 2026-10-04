<?php
/**
 * Le stock qui descend de Felar vers la boutique.
 *
 * <h2>Ce que ces cas protègent</h2>
 * D'abord la règle qui coûte le plus cher si on s'en écarte : <b>on écrit la
 * quantité, on ne la retranche jamais</b>. Ensuite les cinq situations où il ne
 * faut rien écrire du tout — et dont aucune n'est une erreur.
 *
 * @package Felar_Connect
 */

use PHPUnit\Framework\TestCase;

class StockTest extends TestCase {

	/** Une ligne du flux, telle que Felar la rend. */
	private function ligne( array $surcharges = array() ) {
		return array_merge(
			array(
				'felarId'      => '3b9a7c14-0000-0000-0000-000000000001',
				'externalId'   => '1287',
				'reference'    => 'BZ-SAC-01',
				'label'        => 'Sac bazin brodé',
				'available'    => 2,
				'stockTracked' => true,
				'active'       => true,
			),
			$surcharges
		);
	}

	/** Ce que WooCommerce dit de l'article visé. */
	private function faits( array $surcharges = array() ) {
		return array_merge(
			array(
				'existe'        => true,
				'felar_id'      => '3b9a7c14-0000-0000-0000-000000000001',
				'suit_le_stock' => true,
				'stock'         => 7,
				'nom'           => 'Sac bazin brodé',
			),
			$surcharges
		);
	}

	public function test_la_quantite_est_ecrite_telle_quelle() {
		// Le cas nominal, et la règle centrale : 7 devient 2, pas 7 − 2. La boutique
		// a déjà retiré sa part de son côté, Felar a retiré la sienne ; soustraire
		// encore compterait chaque vente deux fois.
		$verdict = Felar_Stock_Regles::decider( $this->ligne(), $this->faits() );

		$this->assertSame( 'ecrire', $verdict['action'] );
		$this->assertSame( 2, $verdict['quantite'] );
		$this->assertNull( $verdict['note'] );
	}

	public function test_une_quantite_deja_bonne_nest_pas_reecrite() {
		// Sauter l'écriture évite de réveiller les crochets de WooCommerce — et les
		// extensions qui y sont branchées — plusieurs fois par heure pour rien.
		$verdict = Felar_Stock_Regles::decider(
			$this->ligne( array( 'available' => 7 ) ),
			$this->faits( array( 'stock' => 7 ) )
		);

		$this->assertSame( 'inchange', $verdict['action'] );
	}

	public function test_un_disponible_negatif_devient_zero() {
		// Felar peut porter un disponible négatif — une vente enregistrée malgré une
		// rupture. WooCommerce l'afficherait comme une quantité en réserve.
		$verdict = Felar_Stock_Regles::decider(
			$this->ligne( array( 'available' => -3 ) ),
			$this->faits()
		);

		$this->assertSame( 'ecrire', $verdict['action'] );
		$this->assertSame( 0, $verdict['quantite'] );
	}

	public function test_un_article_supprime_de_la_boutique_ne_fait_rien() {
		$verdict = Felar_Stock_Regles::decider(
			$this->ligne(),
			array( 'existe' => false )
		);

		$this->assertSame( 'absent', $verdict['action'] );
		$this->assertSame( 'PRODUIT_ABSENT', $verdict['note'] );
	}

	public function test_un_lien_vers_une_autre_fiche_felar_bloque_lecriture() {
		// Cela arrive quand une boutique a été branchée successivement sur deux
		// comptes : écrire ici poserait le stock d'un compte sur le catalogue d'un
		// autre, et personne ne comprendrait d'où vient le chiffre.
		$verdict = Felar_Stock_Regles::decider(
			$this->ligne(),
			$this->faits( array( 'felar_id' => '99999999-0000-0000-0000-000000000009' ) )
		);

		$this->assertSame( 'lien_etranger', $verdict['action'] );
		$this->assertNull( $verdict['quantite'] );
	}

	public function test_un_article_que_felar_ne_vend_plus_est_nomme_et_laisse_en_place() {
		// Dépublier une page que Google indexe est une décision de commerce, pas une
		// décision de synchronisation.
		$verdict = Felar_Stock_Regles::decider(
			$this->ligne( array( 'active' => false ) ),
			$this->faits()
		);

		$this->assertSame( 'retire', $verdict['action'] );
		$this->assertSame( 'RETIRE_DE_FELAR', $verdict['note'] );
		$this->assertNull( $verdict['quantite'] );
	}

	public function test_un_article_dont_felar_ne_suit_pas_le_stock_est_laisse() {
		$verdict = Felar_Stock_Regles::decider(
			$this->ligne( array( 'stockTracked' => false ) ),
			$this->faits()
		);

		$this->assertSame( 'non_suivi_felar', $verdict['action'] );
	}

	public function test_un_article_dont_woocommerce_ne_gere_pas_le_stock_est_signale() {
		// On pourrait cocher la case à sa place — et faire passer d'un coup des
		// articles en « rupture », donc invendables sur son site.
		$verdict = Felar_Stock_Regles::decider(
			$this->ligne(),
			$this->faits( array( 'suit_le_stock' => false ) )
		);

		$this->assertSame( 'non_suivi_woo', $verdict['action'] );
		$this->assertSame( 'STOCK_NON_SUIVI_WOO', $verdict['note'] );
	}

	public function test_une_ligne_sans_quantite_ne_remet_pas_a_zero() {
		// Prendre l'absence de valeur pour un zéro mettrait tout le catalogue en
		// rupture au premier défaut du serveur.
		$verdict = Felar_Stock_Regles::decider(
			$this->ligne( array( 'available' => null ) ),
			$this->faits()
		);

		$this->assertSame( 'sans_quantite', $verdict['action'] );
		$this->assertNull( $verdict['quantite'] );
	}

	public function test_lordre_des_refus_nomme_la_cause_la_plus_profonde() {
		// Un article absent de la boutique ET retiré de Felar se nomme « absent » :
		// c'est le fait que le marchand constatera chez lui.
		$verdict = Felar_Stock_Regles::decider(
			$this->ligne( array( 'active' => false ) ),
			array( 'existe' => false )
		);

		$this->assertSame( 'absent', $verdict['action'] );
	}

	public function test_chaque_code_a_une_phrase_qui_dit_quoi_faire() {
		foreach ( array_keys( Felar_Stock_Regles::phrases() ) as $code ) {
			$phrase = Felar_Stock_Regles::phrase( $code );
			$this->assertNotSame( $code, $phrase );
			$this->assertStringEndsWith( '.', $phrase );
		}
	}
}
