#!/usr/bin/env node
"use strict";

const { chromium } = require("playwright");

const args = process.argv.slice(2);
const url = args.find((arg) => !arg.startsWith("--")) || "http://127.0.0.1:8073/";
const mobile = args.includes("--mobile");
const openAnalytics = args.includes("--analytics");
const json = args.includes("--json");
const throttle = args.includes("--throttle");

const viewport = mobile
  ? { width: 390, height: 844, isMobile: true, hasTouch: true }
  : { width: 1440, height: 1000 };

const thresholds = {
  maxLongTaskTotal: Number(process.env.PERF_MAX_LONG_TASK_TOTAL || (throttle ? 2500 : 900)),
  maxLongTaskCount: Number(process.env.PERF_MAX_LONG_TASK_COUNT || (throttle ? 30 : 18)),
  maxScriptBytes: Number(process.env.PERF_MAX_SCRIPT_BYTES || 180000),
  maxStyleBytes: Number(process.env.PERF_MAX_STYLE_BYTES || 110000),
  maxDomComplete: Number(process.env.PERF_MAX_DOM_COMPLETE || (throttle ? 16000 : 6500)),
};

function round(value) {
  return Math.round(Number(value || 0));
}

async function main() {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({
    viewport,
    isMobile: Boolean(viewport.isMobile),
    hasTouch: Boolean(viewport.hasTouch),
  });
  if (throttle) {
    const cdp = await page.context().newCDPSession(page);
    await cdp.send("Network.enable");
    await cdp.send("Network.emulateNetworkConditions", {
      offline: false,
      latency: 150,
      downloadThroughput: Math.floor((1.6 * 1024 * 1024) / 8),
      uploadThroughput: Math.floor((750 * 1024) / 8),
    });
    await cdp.send("Emulation.setCPUThrottlingRate", { rate: 4 });
  }
  const consoleIssues = [];
  page.on("console", (message) => {
    if (["error", "warning"].includes(message.type())) {
      consoleIssues.push(`${message.type()}: ${message.text()}`);
    }
  });
  page.on("pageerror", (error) => consoleIssues.push(`pageerror: ${error.message}`));

  await page.addInitScript(() => {
    window.__publicationTrackerPerf = { longTasks: [] };
    try {
      const observer = new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          window.__publicationTrackerPerf.longTasks.push({
            name: entry.name,
            startTime: entry.startTime,
            duration: entry.duration,
          });
        }
      });
      observer.observe({ type: "longtask", buffered: true });
    } catch (error) {}
  });

  await page.goto(url, { waitUntil: "networkidle" });
  await page.waitForTimeout(1200);

  if (openAnalytics) {
    const trigger = page.locator("[data-open-analytics]").first();
    if (await trigger.count()) {
      await trigger.click();
      await page.waitForTimeout(700);
    }
  }

  const metrics = await page.evaluate(() => {
    const nav = performance.getEntriesByType("navigation")[0];
    const paints = Object.fromEntries(performance.getEntriesByType("paint").map((entry) => [entry.name, entry.startTime]));
    const resources = performance.getEntriesByType("resource").map((entry) => ({
      name: entry.name,
      type: entry.initiatorType,
      transferSize: entry.transferSize || 0,
      decodedBodySize: entry.decodedBodySize || 0,
      duration: entry.duration || 0,
    }));
    const decodedByPath = (pattern) => resources
      .filter((resource) => pattern.test(new URL(resource.name, location.href).pathname))
      .reduce((sum, resource) => sum + resource.decodedBodySize, 0);
    const perf = window.__publicationTrackerPerf || { longTasks: [] };
    const preloader = document.querySelector("#app-preloader");
    return {
      title: document.title,
      url: location.href,
      nav: nav ? {
        domContentLoaded: nav.domContentLoadedEventEnd,
        load: nav.loadEventEnd,
        domComplete: nav.domComplete,
        transferSize: nav.transferSize || 0,
        decodedBodySize: nav.decodedBodySize || 0,
      } : null,
      paints,
      resources: {
        count: resources.length,
        scriptDecodedBytes: decodedByPath(/\.js$/i),
        styleDecodedBytes: decodedByPath(/\.css$/i),
        imageDecodedBytes: decodedByPath(/\.(?:png|jpe?g|webp|ico|svg)$/i),
      },
      longTasks: perf.longTasks,
      dom: {
        nodes: document.getElementsByTagName("*").length,
        preloaderHidden: Boolean(preloader?.hidden || preloader?.classList.contains("is-hidden")),
        analyticsRendered: Boolean(document.querySelector("#publication-timeline svg")),
        growthRendered: Boolean(document.querySelector("#publication-growth-chart svg")),
      },
    };
  });

  const longTaskTotal = metrics.longTasks.reduce((sum, task) => sum + task.duration, 0);
  const summary = {
    mode: mobile ? "mobile" : "desktop",
    analyticsOpened: openAnalytics,
    throttled: throttle,
    url: metrics.url,
    title: metrics.title,
    domContentLoadedMs: round(metrics.nav?.domContentLoaded),
    loadMs: round(metrics.nav?.load),
    domCompleteMs: round(metrics.nav?.domComplete),
    firstPaintMs: round(metrics.paints["first-paint"]),
    firstContentfulPaintMs: round(metrics.paints["first-contentful-paint"]),
    longTaskCount: metrics.longTasks.length,
    longTaskTotalMs: round(longTaskTotal),
    maxLongTaskMs: round(Math.max(0, ...metrics.longTasks.map((task) => task.duration))),
    scriptDecodedBytes: metrics.resources.scriptDecodedBytes,
    styleDecodedBytes: metrics.resources.styleDecodedBytes,
    imageDecodedBytes: metrics.resources.imageDecodedBytes,
    resourceCount: metrics.resources.count,
    domNodes: metrics.dom.nodes,
    preloaderHidden: metrics.dom.preloaderHidden,
    analyticsRendered: metrics.dom.analyticsRendered,
    growthRendered: metrics.dom.growthRendered,
    consoleIssues,
  };

  const failures = [];
  if (summary.longTaskTotalMs > thresholds.maxLongTaskTotal) failures.push(`longTaskTotalMs ${summary.longTaskTotalMs} > ${thresholds.maxLongTaskTotal}`);
  if (summary.longTaskCount > thresholds.maxLongTaskCount) failures.push(`longTaskCount ${summary.longTaskCount} > ${thresholds.maxLongTaskCount}`);
  if (summary.scriptDecodedBytes > thresholds.maxScriptBytes) failures.push(`scriptDecodedBytes ${summary.scriptDecodedBytes} > ${thresholds.maxScriptBytes}`);
  if (summary.styleDecodedBytes > thresholds.maxStyleBytes) failures.push(`styleDecodedBytes ${summary.styleDecodedBytes} > ${thresholds.maxStyleBytes}`);
  if (summary.domCompleteMs > thresholds.maxDomComplete) failures.push(`domCompleteMs ${summary.domCompleteMs} > ${thresholds.maxDomComplete}`);
  if (consoleIssues.length) failures.push(`${consoleIssues.length} console issue(s)`);

  await browser.close();

  if (json) {
    console.log(JSON.stringify({ summary, thresholds, failures }, null, 2));
  } else {
    console.table(summary);
    if (failures.length) {
      console.error(`Performance check failed: ${failures.join("; ")}`);
    } else {
      console.log("Performance check passed.");
    }
  }

  if (failures.length) process.exit(1);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
