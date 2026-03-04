# WordPress.org DNS verification for CoderEmbassy Wholesale Pricing

To prove you control **coderembassy.com** for the plugin review team, add this TXT record.

## What to add

- **Type:** `TXT`
- **Host / Name:** `@` (or leave blank — means “root” of the domain)
- **Value / Content:** `wordpressorg-codersaleh-verification`
- **TTL:** 300 or default (e.g. 3600) is fine

## How to add it (by provider)

### Generic (any host)

1. Log in to where your domain’s DNS is managed (registrar like Namecheap/GoDaddy, or DNS host like Cloudflare).
2. Open **DNS** / **DNS Management** / **Advanced DNS** for **coderembassy.com**.
3. Click **Add record** (or **Add**).
4. Choose **TXT**.
5. **Host:** leave blank or enter `@` (root of coderembassy.com).
6. **Value:** `wordpressorg-codersaleh-verification` (no quotes, no spaces).
7. Save. Changes can take 5–60 minutes to propagate.

### Cloudflare

- **Type:** TXT  
- **Name:** `@`  
- **Content:** `wordpressorg-codersaleh-verification`  
- **Proxy status:** DNS only (grey cloud)  
- Save.

### Namecheap

- **Advanced DNS** → **Add New Record**  
- **Type:** TXT Record  
- **Host:** `@`  
- **Value:** `wordpressorg-codersaleh-verification`  
- **TTL:** Automatic  
- Save.

### GoDaddy

- **My Products** → **DNS** for coderembassy.com  
- **Add** → **Type:** TXT  
- **Name:** `@`  
- **Value:** `wordpressorg-codersaleh-verification`  
- **TTL:** 1 Hour  
- Save.

### Google Domains / Squarespace Domains

- Open DNS settings for coderembassy.com.  
- Add a **TXT** record: **Host** `@`, **Value** `wordpressorg-codersaleh-verification`.  
- Save.

## Check that it’s live

- **Command line:**  
  `nslookup -type=TXT coderembassy.com`  
  or  
  `dig TXT coderembassy.com`  
  You should see `wordpressorg-codersaleh-verification` in the answer.

- **Online:** Use https://dnschecker.org — search for `coderembassy.com`, type TXT, and confirm the value appears.

## What to tell the Plugin Review Team

After the TXT record is visible, reply to their email with something like:

> I’ve added the TXT record for DNS verification: at the root of coderembassy.com (@) with value **wordpressorg-codersaleh-verification**. You can verify it with:  
> `dig TXT coderembassy.com`  
> Please proceed with the review. Thank you.

You can keep this file for your records or delete it after the plugin is approved.
