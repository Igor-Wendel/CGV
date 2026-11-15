#!/usr/bin/env python3
"""Prepara o site para publicacao: acerta o cache-busting automaticamente.

Antes disso, cada arquivo HTML trazia "?v=20" escrito na mao. Era preciso
lembrar de somar 1 em CADA pagina toda vez que o CSS ou um JS compartilhado
mudava - e esquecer significava usuario rodando com arquivo velho em cache,
o que ja quase aconteceu varias vezes.

Aqui a versao passa a ser o HASH DO CONTEUDO do arquivo. Muda o arquivo, muda
o hash; nao muda, o hash fica igual e o navegador continua aproveitando o
cache. Rodar duas vezes seguidas nao altera nada (idempotente).

Uso:
    python ferramentas/preparar-deploy.py            # aplica
    python ferramentas/preparar-deploy.py --conferir # so verifica, nao grava
"""
import hashlib
import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent

# Arquivos cujo endereco leva "?v=" nas paginas: nome como aparece no HTML -> caminho real.
ASSETS = {
    'style.css': RAIZ / 'frontend' / 'style.css',
    'filtro.js': RAIZ / 'backend' / 'filtro.js',
    'hostinger-storage.js': RAIZ / 'backend' / 'hostinger-storage.js',
    'pfm.js': RAIZ / 'fopag' / 'pfm.js',
}

CONFERIR = '--conferir' in sys.argv


def hash_curto(caminho: Path) -> str:
    """Hash do conteudo, em 8 digitos - o suficiente para nunca colidir aqui.

    CRLF vira LF antes do hash: com core.autocrlf no Windows o arquivo local
    tem CRLF e o checkout do CI (Linux) tem LF - sem isso o --conferir do
    pipeline acusava pagina desatualizada que localmente estava certa."""
    return hashlib.sha1(caminho.read_bytes().replace(b'\r\n', b'\n')).hexdigest()[:8]


def paginas():
    return sorted(list(RAIZ.glob('*.html')) + list(RAIZ.glob('*/*.html')))


def main():
    faltando = [n for n, c in ASSETS.items() if not c.exists()]
    if faltando:
        print(f'ERRO: asset nao encontrado: {", ".join(faltando)}')
        return 1

    hashes = {nome: hash_curto(caminho) for nome, caminho in ASSETS.items()}

    print('Versao por conteudo:')
    for nome, h in hashes.items():
        print(f'  {nome:24s} -> {h}')
    print()

    alterados = []
    for pagina in paginas():
        # newline='' preserva o CRLF/LF de cada arquivo (evita diff falso no git).
        texto = original = pagina.read_text(encoding='utf-8', newline='')

        for nome, h in hashes.items():
            # aceita o formato antigo (?v=20) e um hash ja aplicado
            texto = re.sub(
                re.escape(nome) + r'\?v=[0-9a-f]+',
                f'{nome}?v={h}',
                texto,
            )

        if texto != original:
            alterados.append(pagina.relative_to(RAIZ))
            if not CONFERIR:
                pagina.write_text(texto, encoding='utf-8', newline='')

    if not alterados:
        print('Nada a fazer - todas as paginas ja apontam para a versao atual.')
        return 0

    rotulo = 'DESATUALIZADA' if CONFERIR else 'atualizada'
    for p in alterados:
        print(f'  {rotulo}: {p}')
    print()

    if CONFERIR:
        print(f'{len(alterados)} pagina(s) fora de sincronia.')
        print('Rode sem --conferir para corrigir.')
        return 1

    print(f'{len(alterados)} pagina(s) atualizada(s). Pronto para publicar.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
