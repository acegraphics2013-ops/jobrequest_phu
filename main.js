const { app, BrowserWindow, shell } = require('electron');
const path = require('path');

let mainWindow = null;

// Ensure single instance lock
const gotTheLock = app.requestSingleInstanceLock();

if (!gotTheLock) {
    app.quit();
} else {
    app.on('second-instance', () => {
        if (mainWindow) {
            if (mainWindow.isMinimized()) mainWindow.restore();
            mainWindow.focus();
        }
    });

    app.whenReady().then(createWindow);
}

function createWindow() {
    mainWindow = new BrowserWindow({
        width: 460,
        height: 680,
        minWidth: 400,
        minHeight: 500,
        alwaysOnTop: true,
        title: "PHU Workbench - Chat Center",
        autoHideMenuBar: true,
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true,
            sandbox: true
        }
    });

    // Load the Laragon PHU Workbench Chat Center URL
    const chatUrl = process.env.PHU_CHAT_URL || 'http://localhost/jobrequest_phu/chat_center.php';
    mainWindow.loadURL(chatUrl);

    // Handle new window popups cleanly
    mainWindow.webContents.setWindowOpenHandler(({ url }) => {
        if (url.includes('chat_center.php?popout=1')) {
            return {
                action: 'allow',
                overrideBrowserWindowOptions: {
                    width: 460,
                    height: 680,
                    alwaysOnTop: true,
                    autoHideMenuBar: true,
                    title: 'PHU Floating Chat'
                }
            };
        }
        shell.openExternal(url);
        return { action: 'deny' };
    });

    mainWindow.on('closed', () => {
        mainWindow = null;
    });
}

app.on('window-all-closed', () => {
    if (process.platform !== 'darwin') {
        app.quit();
    }
});

app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
        createWindow();
    }
});
