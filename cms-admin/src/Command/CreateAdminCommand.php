<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Create or update an admin user (username + password).',
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Username/login')
            ->addArgument('password', InputArgument::REQUIRED, 'Plain text password (will be hashed)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $input->getArgument('username');
        $password = $input->getArgument('password');

        $repo = $this->em->getRepository(User::class);
        $u = $repo->findOneBy(['username' => $username]) ?? (new User())->setUsername($username);
        $u->setPassword($this->hasher->hashPassword($u, $password));
        $u->setRoles(['ROLE_ADMIN']);

        $this->em->persist($u);
        $this->em->flush();

        $output->writeln(sprintf('<info>User "%s" saved.</info>', $username));
        return Command::SUCCESS;
    }
}
