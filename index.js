import TelegramBot from "node-telegram-bot-api";
import fs from "fs";
import express from "express";

/* ================= BASIC SETUP ================= */

const bot = new TelegramBot(process.env.BOT_TOKEN, { polling: true });

const app = express();
app.get("/", (req, res) => res.send("Bot Alive 🚀"));
app.listen(process.env.PORT || 3000);

/* ================= DATABASE ================= */

const DB_FILE = "./users.json";
let users = fs.existsSync(DB_FILE)
  ? JSON.parse(fs.readFileSync(DB_FILE))
  : {};

function saveDB() {
  fs.writeFileSync(DB_FILE, JSON.stringify(users, null, 2));
}

/* ================= CONFIG ================= */

const ADMIN_ID = 123456789; // 👉 এখানে নিজের Telegram ID বসাবে
const REFER_BONUS = 5; // প্রতি refer এ 5৳
const MIN_WITHDRAW = 20;

/* ================= KEYBOARD ================= */

const mainKeyboard = {
  reply_markup: {
    keyboard: [
      ["🔗 Refer & Earn", "💰 Balance"],
      ["💸 Withdraw", "📢 Rules"],
      ["🆘 Help"]
    ],
    resize_keyboard: true
  }
};

/* ================= START COMMAND ================= */

bot.onText(/\/start(?:\s+(\d+))?/, (msg, match) => {
  const id = msg.from.id;
  const ref = match[1];

  if (!users[id]) {
    users[id] = {
      referrals: 0,
      balance: 0
    };

    if (ref && ref !== String(id) && users[ref]) {
      users[ref].referrals += 1;
      users[ref].balance += REFER_BONUS;
      bot.sendMessage(
        ref,
        `🎉 New Referral!\n💰 +${REFER_BONUS}৳ added`
      );
    }
    saveDB();
  }

  bot.sendMessage(
    id,
    `👋 Welcome ${msg.from.first_name}!\n\n🎁 Refer friends & earn money!\n\n⬇️ Use buttons below`,
    mainKeyboard
  );
});

/* ================= BUTTON HANDLER ================= */

bot.on("message", (msg) => {
  const text = msg.text;
  const id = msg.from.id;

  if (!users[id]) return;

  /* REFER */
  if (text === "🔗 Refer & Earn") {
    const link = `https://t.me/${process.env.BOT_USERNAME}?start=${id}`;
    bot.sendMessage(
      id,
      `🔗 Your Referral Link:\n${link}\n\n🎁 Earn ${REFER_BONUS}৳ per refer`
    );
  }

  /* BALANCE */
  if (text === "💰 Balance") {
    bot.sendMessage(
      id,
      `💰 Balance: ${users[id].balance}৳\n👥 Referrals: ${users[id].referrals}`
    );
  }

  /* WITHDRAW */
  if (text === "💸 Withdraw") {
    if (users[id].balance < MIN_WITHDRAW) {
      bot.sendMessage(
        id,
        `❌ Minimum withdraw ${MIN_WITHDRAW}৳`
      );
    } else {
      bot.sendMessage(
        id,
        `💸 Withdraw Request\n\nSend like this:\n\nBkash 01XXXXXXXXX\nor\nNagad 01XXXXXXXXX`
      );
    }
  }

  /* RULES */
  if (text === "📢 Rules") {
    bot.sendMessage(
      id,
      `📢 Rules:\n\n✔️ One account per user\n✔️ Fake refer banned\n✔️ Minimum withdraw ${MIN_WITHDRAW}৳`
    );
  }

  /* HELP */
  if (text === "🆘 Help") {
    bot.sendMessage(
      id,
      `🆘 Support:\n\n⏳ Withdraw time: 24 hours\n📩 Contact admin if needed`
    );
  }

  /* WITHDRAW REQUEST FORMAT */
  if (text.startsWith("Bkash") || text.startsWith("Nagad")) {
    bot.sendMessage(
      ADMIN_ID,
      `💸 Withdraw Request\n\nUser: ${id}\nMessage: ${text}\nBalance: ${users[id].balance}৳`
    );
    bot.sendMessage(
      id,
      "✅ Withdraw request sent. Please wait."
    );
  }
});

/* ================= ADMIN BROADCAST ================= */

bot.onText(/\/broadcast (.+)/, (msg, match) => {
  if (msg.from.id !== ADMIN_ID) return;

  const message = match[1];
  Object.keys(users).forEach((uid) => {
    bot.sendMessage(uid, `📢 Broadcast:\n\n${message}`);
  });
});
