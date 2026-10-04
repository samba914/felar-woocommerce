<?php
/**
 * Le rapport lu avant l'import.
 *
 * <h2>Ce que ces cas protègent</h2>
 * Le compte des <b>UGS portées par deux articles</b>. C'est la seule information du
 * rapport qui ne se sait qu'à la fin — la première occurrence n'est un doublon
 * qu'au moment où la seconde arrive — et donc la seule qu'un comptage naïf rate,
 * en n'écartant que la seconde.
 *
 * @package Felar_Connect
 */

use PHPUnit\Framework\TestCase;

class RapportTest extends TestCase {

	private function conversion( $mode, array $lignes, array $notes = array(), $ecarte = null ) {
		return array(
			'mode'   => $mode,
			'lignes' => $lignes,
			'notes'  => $notes,
			'ecarte' => $ecarte,
		);
	}

	private function ligne( $reference, $intitule = 'Article' ) {
		$ligne = array(
			'externalId' => '1',
			'label'      => $intitule,
		);
		if ( '' !== $reference ) {
			$ligne['reference'] = $reference;
		}
		return $ligne;
	}

	public function test_les_compteurs_suivent_les_modes() {
		$rapport = Felar_Rapport::vide();
		$rapport = Felar_Rapport::ajouter( $rapport, 'Sac', $this->conversion( 'simple', array( $this->ligne( 'A-1' ) ) ) );
		$rapport = Felar_Rapport::ajouter(
			$rapport,
			'Robe',
			$this->conversion( 'declinaisons', array( $this->ligne( 'B-38' ), $this->ligne( 'B-40' ) ) )
		);
		$rapport = Felar_Rapport::ajouter( $rapport, 'Lot', $this->conversion( 'ecarte', array(), array(), 'TYPE_NON_GERE' ) );

		$resume = Felar_Rapport::resume( $rapport );

		$this->assertSame( 3, $resume['produits'] );
		$this->assertSame( 1, $resume['simples'] );
		$this->assertSame( 1, $resume['modeles'] );
		$this->assertSame( 2, $resume['declinaisons'] );
		$this->assertSame( 1, $resume['ecartes'] );
		$this->assertSame( 3, $resume['lignes'] );
		$this->assertSame( 3, $resume['a_envoyer'] );
	}

	public function test_une_ugs_en_double_ecarte_les_deux_articles() {
		// Les fusionner serait une décision de catalogue, pas une décision d'import.
		$rapport = Felar_Rapport::vide();
		$rapport = Felar_Rapport::ajouter( $rapport, 'Sac noir', $this->conversion( 'simple', array( $this->ligne( 'BZ-01', 'Sac noir' ) ) ) );
		$rapport = Felar_Rapport::ajouter( $rapport, 'Sac copie', $this->conversion( 'simple', array( $this->ligne( 'BZ-01', 'Sac copie' ) ) ) );

		$resume = Felar_Rapport::resume( $rapport );

		$this->assertSame( 2, $resume['lignes'] );
		$this->assertSame( 0, $resume['a_envoyer'] );
		$this->assertSame( 1, $resume['references_en_trop'] );
		$this->assertSame( 2, $resume['lignes_doublons'] );
		$this->assertSame( array( 'Sac noir', 'Sac copie' ), $rapport['doublons']['bz-01'] );
	}

	public function test_la_casse_et_les_espaces_ne_font_pas_deux_references() {
		// « bz-01 » et « BZ-01 » sont la même étiquette pour un caissier, et Felar
		// refuserait la seconde.
		$rapport = Felar_Rapport::vide();
		$rapport = Felar_Rapport::ajouter( $rapport, 'A', $this->conversion( 'simple', array( $this->ligne( 'BZ-01' ) ) ) );
		$rapport = Felar_Rapport::ajouter( $rapport, 'B', $this->conversion( 'simple', array( $this->ligne( ' bz-01 ' ) ) ) );

		$this->assertSame( 1, Felar_Rapport::resume( $rapport )['references_en_trop'] );
	}

	public function test_les_articles_sans_ugs_sont_comptes_sans_etre_ecartes() {
		$rapport = Felar_Rapport::vide();
		$rapport = Felar_Rapport::ajouter( $rapport, 'A', $this->conversion( 'simple', array( $this->ligne( '' ) ) ) );
		$rapport = Felar_Rapport::ajouter( $rapport, 'B', $this->conversion( 'simple', array( $this->ligne( '' ) ) ) );

		$resume = Felar_Rapport::resume( $rapport );

		$this->assertSame( 2, $resume['sans_reference'] );
		$this->assertSame( 0, $resume['references_en_trop'] );
		$this->assertSame( 2, $resume['a_envoyer'] );
	}

	public function test_les_notes_gardent_quelques_exemples_et_pas_plus() {
		$rapport = Felar_Rapport::vide();
		for ( $rang = 1; $rang <= 12; $rang++ ) {
			$rapport = Felar_Rapport::ajouter(
				$rapport,
				'Produit ' . $rang,
				$this->conversion( 'simple', array( $this->ligne( 'R-' . $rang ) ), array( 'STOCK_PARENT' ) )
			);
		}

		$this->assertSame( 12, $rapport['notes']['STOCK_PARENT']['nombre'] );
		$this->assertCount( Felar_Rapport::EXEMPLES_MAX, $rapport['notes']['STOCK_PARENT']['exemples'] );
	}

	public function test_le_nombre_dappels_suit_les_cent_lignes_par_envoi() {
		$rapport = Felar_Rapport::vide();
		for ( $rang = 1; $rang <= 250; $rang++ ) {
			$rapport = Felar_Rapport::ajouter( $rapport, 'P' . $rang, $this->conversion( 'simple', array( $this->ligne( 'R-' . $rang ) ) ) );
		}

		$this->assertSame( 3, Felar_Rapport::resume( $rapport )['appels'] );
	}

	public function test_les_references_refusees_sont_pretes_a_tester() {
		$rapport = Felar_Rapport::vide();
		$rapport = Felar_Rapport::ajouter( $rapport, 'A', $this->conversion( 'simple', array( $this->ligne( 'BZ-01' ) ) ) );
		$rapport = Felar_Rapport::ajouter( $rapport, 'B', $this->conversion( 'simple', array( $this->ligne( 'BZ-01' ) ) ) );
		$rapport = Felar_Rapport::ajouter( $rapport, 'C', $this->conversion( 'simple', array( $this->ligne( 'BZ-02' ) ) ) );

		$refusees = Felar_Rapport::references_refusees( $rapport );

		$this->assertArrayHasKey( 'bz-01', $refusees );
		$this->assertArrayNotHasKey( 'bz-02', $refusees );
	}
}
