/**
 * Paid Course SaaS Bot (JSON Version)
 * MongoDB ছাড়া – Beginner & Production Starter
 */

import TelegramBot from "node-telegram-bot-api";
import fs from "fs";
import express from "express";

/* ================= BASIC ================= */

const BOT_TOKEN = process.env.BOT_TOKEN;
const ADMIN_ID = Number(process.env.ADMIN_ID);

const bot = new TelegramBot(BOT_TOKEN, { polling: true });

const app = express();
app.get("/", (req, res) => res.send("Paid Course Bot Alive 🚀"));
app.listen(process.env.PORT || 3000);

/* ================= DATABASE (JSON) ================= */

const USERS_DB = "./users.json";
const PAY_DB = "./payments.json";

let users = fs.existsSync(USERS_DB)
  ? JSON.parse(fs.readFileSync(USERS_DB))
  : {};

let payments = fs.existsSync(PAY_DB)
  ? JSON.parse(fs.readFileSync(PAY_DB))
  : [];

function saveUsers() {
  fs.writeFileSync(USERS_DB, JSON.stringify(users, null, 2));
}

function savePayments() {
  fs.writeFileSync(PAY_DB, JSON.stringify(payments, null, 2));
}

/* ================= KEYBOARD ================= */

const mainKeyboard = {
  reply_markup: {
    keyboard: [
      ["📚 Courses", "🧾 My Courses"],
      ["💳 Buy Course"],
      ["🆘 Help"]
    ],
    resize_keyboard: true
  }
};

/* ================= START ================= */

bot.onText(/\/start/, (msg) => {
  const id = msg.from.id;

  if (!users[id]) {
    users[id] = {
      id,
      name: msg.from.first_name,
      role: id === ADMIN_ID ? "admin" : "user",
      courses: []
    };
    saveUsers();
  }

  bot.sendMessage(
    id,
    `👋 Welcome ${users[id].name}!\n\n🎓 Paid Course Platform\n\n⬇️ Use menu below`,
    mainKeyboard
  );
});

/* ================= MENU HANDLER ================= */

bot.on("message", (msg) => {
  const text = msg.text;
  const id = msg.from.id;
  const user = users[id];
  if (!user) return;

  // COURSES
  if (text === "📚 Courses") {
    bot.sendMessage(
      id,
      "📚 Available Courses:\n\n🔒 JavaScript Basics\n🔒 Node.js Mastery\n\n💳 Buy course to unlock"
    );
  }

  // MY COURSES
  if (text === "🧾 My Courses") {
    if (user.courses.length === 0) {
      bot.sendMessage(id, "🧾 You have no unlocked courses.");
    } else {
      bot.sendMessage(
        id,
        `🧾 Your Courses:\n\n${user.courses.join("\n")}`
      );
    }
  }

  // BUY COURSE
  if (text === "💳 Buy Course") {
    bot.sendMessage(
      id,
      "💳 Send payment info:\n\nBkash / Nagad\nExample:\nBkash 01XXXXXXXXX JavaScript"
    );
  }

  // HELP
  if (text === "🆘 Help") {
    bot.sendMessage(
      id,
      "🆘 Help\n\n📩 Contact Admin\n⏳ Course unlock after payment approval"
    );
  }

  // PAYMENT MESSAGE
  if (
    text.startsWith("Bkash") ||
    text.startsWith("Nagad")
  ) {
    payments.push({
      userId: id,
      message: text,
      status: "pending"
    });
    savePayments();

    bot.sendMessage(id, "✅ Payment request sent. Please wait.");

    bot.sendMessage(
      ADMIN_ID,
      `💳 New Payment Request\n\nUser: ${id}\nMessage: ${text}`
    );
  }
});

/* ================= ADMIN COMMANDS ================= */

// BROADCAST
bot.onText(/\/broadcast (.+)/, (msg, match) => {
  if (msg.from.id !== ADMIN_ID) return;

  const message = match[1];
  Object.keys(users).forEach((uid) => {
    bot.sendMessage(uid, `📢 Announcement:\n\n${message}`);
  });
});

// UNLOCK COURSE
bot.onText(/\/unlock (\d+) (.+)/, (msg, match) => {
  if (msg.from.id !== ADMIN_ID) return;

  const userId = match[1];
  const course = match[2];

  if (users[userId]) {
    users[userId].courses.push(course);
    saveUsers();
    bot.sendMessage(userId, `🎉 Course Unlocked: ${course}`);
    bot.sendMessage(ADMIN_ID, "✅ Course unlocked successfully");
  }
});
