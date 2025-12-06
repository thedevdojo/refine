/**
 * Refine Background Service Worker
 *
 * Handles context menu registration and message passing between
 * the extension and content scripts.
 */

// Create the context menu when the extension is installed or updated
chrome.runtime.onInstalled.addListener(() => {
  chrome.contextMenus.create({
    id: 'refine-edit',
    title: 'Edit in Refine',
    contexts: ['all'],
  });

  console.log('Refine: Context menu registered');
});

// Handle context menu clicks
chrome.contextMenus.onClicked.addListener((info, tab) => {
  if (info.menuItemId === 'refine-edit') {
    // Send a message to the content script to initiate editing
    chrome.tabs.sendMessage(tab.id, {
      action: 'refine-open-editor',
      frameId: info.frameId || 0,
    });
  }
});

// Handle messages from content scripts
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.action === 'refine-log') {
    console.log('Refine:', request.message);
  }

  return true;
});
