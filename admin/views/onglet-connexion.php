<?php
/**
 * L'appairage, et ce que WooCommerce dit de lui-même.
 *
 * Les deux réglages de taxe sont affichés parce qu'ils décident du sens de la TVA
 * sur tout le catalogue : le marchand doit pouvoir les vérifier d'un coup d'œil,
 * avant l'import et non après la première facture.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

$felar_appairage = (int) $reglages->lire( 'appairage' );
?>

<div class="felar-grille">

	<div class="felar-carte">
		<h2>Votre clé Felar</h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="felar_enregistrer">
			<?php wp_nonce_field( Felar_Admin::JETON ); ?>

			<p>
				<label for="felar-cle"><strong>Clé de connecteur</strong></label><br>
				<input type="password" id="felar-cle" name="cle" class="regular-text felar-large"
				       autocomplete="new-password" spellcheck="false"
				       placeholder="<?php echo $reglages->branchee()
					       ? esc_attr( 'Clé en place : ' . Felar_Client::masquer( $reglages->cle() ) )
					       : 'ck_live_…'; ?>">
				<span class="description">
					<?php if ( $reglages->branchee() ) : ?>
						Laissez ce champ vide pour conserver la clé en place. Felar ne
						peut pas vous la réafficher : seule son empreinte est conservée
						là-bas, et c'est précisément la garantie recherchée.
					<?php else : ?>
						Felar → Réglages → Mon site. Elle vaut un mot de passe : si elle
						fuit, révoquez-la dans Felar, la révocation est immédiate.
					<?php endif; ?>
				</span>
			</p>

			<?php if ( $reglages->branchee() ) : ?>
				<p>
					<label>
						<input type="checkbox" name="oublier_cle" value="1">
						Débrancher cette boutique et effacer la clé
					</label>
				</p>
			<?php endif; ?>

			<p>
				<label for="felar-taux"><strong>Taux de TVA à appliquer par défaut</strong></label><br>
				<input type="number" id="felar-taux" name="taux_declare" step="0.01" min="0" max="100"
				       value="<?php echo esc_attr( $reglages->lire( 'taux_declare' ) ); ?>" class="small-text"> %
				<span class="description">
					Employé lorsque WooCommerce n'applique pas de taxe, ou n'a aucun taux
					défini pour votre pays. <strong>Zéro est une réponse valable</strong> :
					Felar refuse de deviner un taux, parce qu'un taux supposé fausse un
					catalogue entier sans que rien ne le signale.
				</span>
			</p>

			<p>
				<label>
					<input type="checkbox" name="avec_brouillons" value="1"
						<?php checked( (bool) $reglages->lire( 'avec_brouillons' ) ); ?>>
					Importer aussi les brouillons
				</label>
				<span class="description">
					À cocher si vous vendez au comptoir des articles que le site ne
					publie pas.
				</span>
			</p>

			<details class="felar-avance">
				<summary>Réglage avancé</summary>
				<p>
					<label for="felar-serveur">Adresse du serveur Felar</label><br>
					<input type="url" id="felar-serveur" name="serveur" class="regular-text felar-large"
					       value="<?php echo esc_attr( $reglages->serveur() ); ?>">
					<span class="description">
						À ne changer que pour une recette. Le HTTPS est exigé — une adresse
						en clair enverrait votre clé en clair sur le réseau — à la seule
						exception d'une adresse sur cette machine
						(<code>http://localhost</code>), où rien ne sort.
					</span>
					<?php if ( Felar_Contrat::adresse_locale( $reglages->serveur() ) ) : ?>
						<span class="description">
							<strong>Adresse locale en place.</strong> Ce réglage est celui d'une
							recette : remettez l'adresse de production avant d'ouvrir la
							boutique.
						</span>
					<?php endif; ?>
				</p>
			</details>

			<p class="felar-actions">
				<button type="submit" class="button button-primary">Enregistrer</button>
				<button type="button" class="button" id="felar-tester">Tester la connexion</button>
			</p>
		</form>

		<div id="felar-resultat-test" class="felar-resultat" aria-live="polite"></div>

		<?php if ( $felar_appairage > 0 ) : ?>
			<table class="felar-faits">
				<tbody>
					<tr>
						<th scope="row">Compte Felar</th>
						<td><?php echo esc_html( (string) $reglages->lire( 'compte' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row">Devise du compte</th>
						<td><?php echo esc_html( (string) $reglages->lire( 'devise' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row">Mode</th>
						<td>
							<?php echo 'test' === $reglages->lire( 'mode' )
								? 'Essai — rien n\'est enregistré dans Felar'
								: 'Production'; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Module Boutique</th>
						<td>
							<?php if ( $reglages->lire( 'module_actif' ) ) : ?>
								Actif
							<?php else : ?>
								<strong>Inactif.</strong> Votre clé est reconnue, mais votre
								abonnement Felar ne comprend pas ce module : tout le reste
								vous sera refusé. Activez-le dans Felar.
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Dernier test</th>
						<td><?php echo esc_html( date_i18n( 'j F Y à H:i', $felar_appairage ) ); ?></td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div class="felar-carte">
		<h2>Ce que dit votre boutique</h2>
		<p class="description">
			Ces valeurs sont lues dans WooCommerce, jamais devinées. Se tromper sur la
			deuxième inverse la TVA sur tout votre catalogue.
		</p>

		<table class="felar-faits">
			<tbody>
				<tr>
					<th scope="row">Devise</th>
					<td><?php echo esc_html( $boutique['devise'] ); ?></td>
				</tr>
				<tr>
					<th scope="row">Prix saisis</th>
					<td>
						<?php echo $boutique['prix_ttc'] ? 'taxe comprise (TTC)' : 'hors taxe (HT)'; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Taxe</th>
					<td>
						<?php if ( $boutique['taxe_active'] ) : ?>
							activée
						<?php else : ?>
							désactivée — c'est le taux déclaré ci-contre qui sera employé
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Pays de la boutique</th>
					<td>
						<?php echo esc_html( isset( $boutique['pays']['country'] ) ? $boutique['pays']['country'] : '—' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Taux trouvés</th>
					<td>
						<?php if ( empty( $boutique['taux'] ) ) : ?>
							aucun
						<?php else : ?>
							<?php
							$felar_lignes = array();
							foreach ( $boutique['taux'] as $felar_classe => $felar_taux ) {
								$felar_lignes[] = ( '' === $felar_classe ? 'standard' : $felar_classe )
									. ' : ' . $felar_taux . ' %';
							}
							echo esc_html( implode( ' — ', $felar_lignes ) );
							?>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<h3>Ce qui circule, et dans quel sens</h3>
		<ul class="felar-liste">
			<li><strong>Intitulé, description, prix, photos, catégorie, TVA</strong> : de WooCommerce vers Felar.</li>
			<li><strong>Stock</strong> : de Felar vers WooCommerce. Il bouge à la caisse, à la réception et à l'inventaire — votre site ne voit qu'une partie des mouvements, Felar les voit tous.</li>
			<li><strong>Prix d'achat, marges, fournisseurs</strong> : restent dans Felar. Ils n'ont rien à faire sur un site public.</li>
		</ul>
	</div>
</div>
