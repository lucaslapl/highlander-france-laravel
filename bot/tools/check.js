import { statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Diagnostic exécutable sans terminal : `npm run check`
 * (bouton « Run script » de l'UI Node.js Plesk, si disponible).
 * Affiche tout ce qu'il faut savoir sur l'environnement d'exécution.
 */
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');

console.log(`Node       : ${process.version}`);
console.log(`Plateforme : ${process.platform} ${process.arch}`);
console.log(`CWD        : ${process.cwd()}`);
console.log('');

console.log('=== Variables d\'environnement ===');

for (const name of ['PORT', 'DISCORD_TOKEN', 'GUILD_ID', 'SITE_WEBHOOK_URL', 'SITE_WEBHOOK_TOKEN', 'SYNC_INTERVAL_MINUTES']) {
    const value = process.env[name];

    if (value === undefined || value === '') {
        console.log(`MANQUANT  ${name}`);
    } else if (name === 'DISCORD_TOKEN' || name === 'SITE_WEBHOOK_TOKEN') {
        console.log(`OK        ${name} (${value.length} caractères)`);
    } else {
        console.log(`OK        ${name} = ${value}`);
    }
}

console.log('');
console.log('=== Fichiers critiques ===');

for (const file of [
    'package.json',
    'src/index.js',
    'src/config.js',
    'src/services/siteSync.js',
    'src/events/ready.js',
    'src/events/guildMemberAdd.js',
    'src/events/guildMemberRemove.js',
]) {
    try {
        statSync(path.join(root, file));
        console.log(`OK        ${file}`);
    } catch {
        console.log(`MANQUANT  ${file}`);
    }
}

console.log('');
console.log('=== Modules ===');

for (const name of ['dotenv', 'discord.js']) {
    try {
        await import(name);
        console.log(`OK        ${name}`);
    } catch (error) {
        console.log(`ERREUR    ${name} : ${error.message.split('\n')[0]}`);
    }
}

console.log('');
console.log('Diagnostic terminé.');
