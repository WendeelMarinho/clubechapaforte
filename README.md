# Clube Chapa Forte

Aplicação web de **fidelidade e recompensas** (base **Laravel 10**), voltada a clubes, cartões de fidelidade, sócios/membros, parceiros e equipe operacional. O projeto original no código aparece como *Reward Loyalty* (`APP_NAME` em `.env`); este repositório é a variante **Clube Chapa Forte**.

## Visão geral da arquitetura

| Camada | Tecnologia |
|--------|------------|
| Backend | PHP 8.1+, Laravel 10 |
| API REST | Prefixo `api`, versão `v1`, Sanctum (tokens) |
| Frontend web | Blade + **Vite** + **Tailwind CSS** + **Alpine.js** + **HTMX** + Flowbite / tw-elements |
| Banco | MySQL (padrão em `.env.example`) |
| Mídia | Spatie Laravel Media Library |
| Documentação da API | L5-Swagger (OpenAPI), anotações em `app/Http/Controllers/Api` |

Não há repositório frontend separado: assets são compilados pelo Vite a partir de `resources/` e servidos pelo Laravel.

## Domínio do negócio (modelo mental)

- **Networks** — redes / agrupamentos.
- **Clubs** — clubes dentro da rede.
- **Cards** — cartões de fidelidade (programas) associados a clubes.
- **Members** — usuários finais (sócios); seguem cartões, resgatam recompensas, histórico de transações.
- **Partners** — empresas parceiras; gestão de clubes/cartões e transações (compras / pontos) via painel e API.
- **Staff** — equipe no ponto (scanner QR, ganhar pontos, resgate de recompensas, transações).
- **Admins** — administração global; CRUDs via sistema de *Data Definitions*.
- **Affiliates** — afiliados (modelo presente nas migrações).
- **Transactions**, **Rewards**, **Analytics** — operações, recompensas e métricas.

IDs numéricos usam **Snowflake** (`kra8/laravel-snowflake`) para escalabilidade.

## Rotas e papéis

### Web (`routes/web.php`)

Todas as rotas públicas autenticáveis ficam sob **`/{locale}/...`** (redirecionamento automático da raiz `/` para o locale preferido).

- **Membro** — home, cartão, recompensas, login/registro, dashboard, gestão de dados autorizada.
- **Staff** — `/staff/...` — login, scanner QR, earn/claim, transações, CRUDs via Data Definitions.
- **Partner** — `/partner/...` — transações, analytics, CRUDs.
- **Admin** — `/admin/...` — painel e migrações (rotas específicas); CRUDs para perfis autorizados.
- **Instalação** — `/install` (quando o app ainda não está marcado como instalado).

Middlewares principais: `installed`, `member.auth`, `staff.auth`, `partner.auth`, `admin.auth`, guards e papéis por números (`member.role`, etc.).

### API (`routes/api.php`)

Base: **`/api/{locale}/v1`**.

- **Member** — `POST .../member/login`, `register`; com `auth:member_api`: perfil, logout, listagens de cartões, saldo por cartão.
- **Partner** — `POST .../partner/login`; com `auth:partner_api`: clubes, cartões, transações (purchases/points), staff.

Autenticação API: **Laravel Sanctum** (`member_api` / `partner_api`).

### Documentação OpenAPI

Configurada em `config/l5-swagger.php`. Interface típica: **`/api/documentation`** (gerar/atualizar docs conforme documentação do pacote `darkaonline/l5-swagger`).

## Estrutura de pastas relevante

```
app/
  DataDefinitions/     # Definições de CRUD dinâmico (models por contexto admin/partner/staff/member)
  Http/Controllers/    # Web, Api, Installation, Data/*, etc.
  Models/              # Admin, Partner, Member, Staff, Club, Card, Transaction, ...
  Services/            # Regras de negócio (I18n, Data, ...)
database/migrations/   # Esquema MySQL
resources/
  js/                  # Entrada Vite (Alpine, HTMX, QR, formulários)
  css/                 # Tailwind / app
  views/               # Blade
routes/                # web.php, api.php, console.php
```

## Requisitos

- PHP **8.1+** com extensões usuais do Laravel + **intl** (obrigatório para i18n de URLs).
- Composer
- Node.js + npm (para Vite)
- MySQL (ou ajustar `.env` para outro driver suportado pelo Laravel)

## Configuração local

1. Clonar o repositório e entrar na pasta do projeto.

2. Instalar dependências PHP e JS:

   ```bash
   composer install
   npm install
   ```

3. Ambiente:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   Ajuste `DB_*`, `APP_URL`, `APP_NAME` e demais variáveis em `.env`.

4. Banco e storage:

   ```bash
   php artisan migrate
   php artisan storage:link
   ```

5. Primeira execução / instalação guiada: acesse a aplicação no navegador e siga o fluxo em **`/install`** se o app ainda não estiver instalado (`APP_IS_INSTALLED` em `config/default.php` / `.env` conforme o projeto).

6. Desenvolvimento frontend:

   ```bash
   npm run dev
   ```

   Em outro terminal:

   ```bash
   php artisan serve
   ```

7. Build de produção:

   ```bash
   npm run build
   ```

## Testes e qualidade

```bash
php artisan test
./vendor/bin/pint        # estilo de código (se configurado no projeto)
```

## Git e GitHub

Remote sugerido:

```text
git@github.com:WendeelMarinho/clubechapaforte.git
```

Primeiro push (após commit inicial):

```bash
git branch -M main
git remote add origin git@github.com:WendeelMarinho/clubechapaforte.git
git push -u origin main
```

**Não commitar** `.env`, `node_modules/` nem `vendor/` — estão no `.gitignore`.

## Licença

O `composer.json` declara licença **MIT** para o esqueleto Laravel; confira dependências e conteúdo proprietário antes de redistribuir.

---

*Documentação gerada a partir da estrutura do repositório; ajuste `APP_NAME` e textos de marca no `.env` e nas views conforme o Clube Chapa Forte.*
