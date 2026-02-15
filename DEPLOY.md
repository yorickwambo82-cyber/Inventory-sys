# Deploying PhoneStore Pro to Vercel

This guide will help you deploy your PHP application to Vercel.

## Prerequisites

1.  **Vercel Account**: Sign up at [vercel.com](https://vercel.com).
2.  **GitHub Account**: Required for Vercel integration (recommended).
3.  **Remote Database**: Since Vercel is serverless, you need a database hosted elsewhere.

## Step 1: Set up a Remote Database

You cannot use "localhost" or XAMPP's database on Vercel. You need a cloud MySQL database.

**Recommended Free/Cheap Options:**
*   [Aiven](https://aiven.io/) (Free MySQL tier)
*   [PlanetScale](https://planetscale.com/) (Great for scalability)
*   [FreeSQLDatabase](https://www.freesqldatabase.com/) (Good for testing)

**Action:**
1.  Create an account on one of the providers above.
2.  Create a new MySQL database.
3.  Note down the: `Host`, `Database Name`, `User`, `Password`, and `Port`.
4.  **Import your data**:
    *   Export your local database: Open phpMyAdmin > `phonestore_db` > Export > Quick > Go.
    *   Import this `.sql` file into your new remote database using their provided tool or a client like DBeaver/HeidiSQL.

## Step 2: Push to GitHub

1.  Initialize git if you haven't:
    ```bash
    git init
    git add .
    git commit -m "Initial commit"
    ```
2.  Create a repository on GitHub.
3.  Push your code:
    ```bash
    git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPO.git
    git push -u origin main
    ```

## Step 3: Deploy to Vercel

1.  Go to your Vercel Dashboard at [vercel.com/new](https://vercel.com/new).
2.  **Select Repository**: Click **Import** next to your `phonestore` repository.
3.  **Configure Project Screen**:
    *   You will see a screen titled **"Configure Project"**.
    *   **Framework Preset**: Leave as "Other".
    *   **Root Directory**: Leave as `./`.
    *   **Environment Variables**: **CLICK TO EXPAND THIS SECTION**. It is often hidden/collapsed.
    *   Add your database credentials here (Name: `DB_HOST`, Value: `...`, then click Add, etc.).
4.  Click **Deploy**.

### Missed the Environment Variables?
If you already deployed and forgot them, don't worry:
1.  Go to your Project Dashboard on Vercel.
2.  Click **Settings** (top tab).
3.  Click **Environment Variables** (left sidebar).
4.  Add them there (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`).
5.  **IMPORTANT**: You must go to the **Deployments** tab and **Redeploy** (dots menu > Redeploy) for changes to take effect.

## Troubleshooting

-   **"Database Connection Error"**: Check your Environment Variables in Vercel Settings. Ensure the host and password are correct.
-   **"404 Not Found"**: Ensure `vercel.json` is in the root directory.
-   **Logging**: If something goes wrong, check the "Logs" tab in your Vercel deployment to see the error messages.
