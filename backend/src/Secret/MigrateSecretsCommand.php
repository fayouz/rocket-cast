<?php

namespace App\Secret;

use App\Entity\PairingRequest;
use App\Entity\Source;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Secrets\SecretVault;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Moves the credentials sealed by the former libsodium store ("v1:…", App\Secret\SodiumSecretStore) into the rocket-core
 * vault (App\Secret\VaultSecretStore), then deletes the vault secrets "cast.credentials.*" no longer referenced.
 * Idempotent: values already in the vault are left as they are.
 */
#[AsCommand(name: 'app:secrets:migrate', description: 'Move the credentials of the sources from the former encrypted column into the rocket-core secrets vault.')]
final class MigrateSecretsCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SecretVault $vault,
        private readonly SodiumSecretStore $legacy,
        private readonly SecretStoreInterface $store,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Show what would be done, without changing anything')] bool $dryRun = false,
    ): int {
        if (!$this->vault->isConfigured()) {
            $io->error((string) $this->vault->configurationError());

            return Command::FAILURE;
        }
        $rows = [];
        $failures = 0;
        $referenced = [];
        foreach ($this->em->getRepository(Source::class)->findAll() as $source) {
            $sealed = $source->getSealedSecrets();
            if (VaultSecretStore::isVaultReference($sealed)) {
                $referenced[$sealed] = true;
                continue;
            }
            if ('' === $sealed) {
                continue;
            }
            try {
                $secrets = $this->legacy->open($sealed);
            } catch (SecretException $e) {
                $rows[] = ['Source « '.$source->getName().' »', 'illisible : '.$e->getMessage()];
                ++$failures;
                continue;
            }
            if (!$dryRun) {
                $source->setSealedSecrets($reference = $this->store->seal($secrets));
                $referenced[$reference] = true;
            }
            $rows[] = ['Source « '.$source->getName().' »', $dryRun ? 'à déplacer dans le coffre' : 'déplacée dans le coffre'];
        }
        foreach ($this->em->getRepository(PairingRequest::class)->findAll() as $pairing) {
            $sealed = (string) $pairing->getSealedToken();
            if (VaultSecretStore::isVaultReference($sealed)) {
                $referenced[$sealed] = true;
            } elseif ('' !== $sealed && !$dryRun) {
                // Waiting kiosk token of the former format: the screen will simply ask for a new code.
                $this->em->remove($pairing);
                $rows[] = ['Appairage '.$pairing->getCode(), 'supprimé (code à redemander)'];
            }
        }
        if (!$dryRun) {
            $this->em->flush();
        }
        foreach ($this->vault->list() as $secret) {
            $reference = VaultSecretStore::PREFIX.$secret->getName();
            if (str_starts_with($secret->getName(), VaultSecretStore::NAME_PREFIX) && !isset($referenced[$reference])) {
                if (!$dryRun) {
                    $this->vault->delete($secret->getName());
                }
                $rows[] = [$secret->getName(), $dryRun ? 'orphelin, à supprimer' : 'orphelin, supprimé'];
            }
        }
        $io->table(['Élément', 'Résultat'], $rows ?: [['—', 'rien à faire']]);
        if ($failures > 0) {
            $io->warning(\sprintf('%d source(s) illisible(s) : mettre l’ancienne clé dans CAST_LEGACY_SECRETS_KEY, ou ressaisir leurs identifiants.', $failures));

            return Command::FAILURE;
        }
        $io->success($dryRun ? 'Simulation : rien n’a été modifié.' : 'Terminé.');

        return Command::SUCCESS;
    }
}
