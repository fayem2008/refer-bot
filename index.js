import TelegramBot from "node-telegram-bot-api";
import fs from "fs";
import express from "express";

const bot = new TelegramBot(process.env.BOT_TOKEN, { polling: true });

const app = express();
app.get("/", (req, res) => res.send("Refer Bot Alive 🚀"));
app.listen(process.env.PORT || 3000);

const DB_FILE = "./users.json";
let users = {};

if (fs.existsSync(DB_FILE)) {
  users = JSON.parse(fs.readFileSync(DB_FILE));
}

function saveDB() {
  fs.writeFileSync(DB_FILE, JSON.stringify(users, null, 2));
}

bot.onText(/\/start(?:\s+(\d+))?/, (msg, match) => {
  const userId = msg.from.id;
  const refId = match[1];

  if (!users[userId]) {
    users[userId] = { referrals: 0, referredBy: null };

    if (refId && refId !== String(userId) && users[refId]) {
      users[userId].referredBy = refId;
      users[refId].referrals += 1;

      bot.sendMessage(refId, "🎉 You got a new referral!");
    }
    saveDB();
  }

  const link = `https://t.me/${process.env.BOT_USERNAME}?start=${userId}`;

  bot.sendMessage(
    userId,
    `👋 Welcome!\n\n🔗 Your Refer Link:\n${link}\n\n📊 Total Referrals: ${users[userId].referrals}`,
    {
      reply_markup: {
        inline_keyboard: [
          [{ text: "📢 Share Link", url: link }],
          [{ text: "📊 My Referrals", callback_data: "refs" }]
        ]
      }
    }
  );
});

bot.on("callback_query", (q) => {
  if (q.data === "refs") {
    bot.answerCallbackQuery(q.id);
    bot.sendMessage(q.from.id, `📊 Your Referrals: ${users[q.from.id].referrals}`);
  }
});