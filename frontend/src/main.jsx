import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import '@fontsource/saira-condensed/400.css'
import '@fontsource/eb-garamond/400.css'
import '@fontsource/eb-garamond/400-italic.css'
import '@fontsource/jetbrains-mono/400.css'
import './index.css'
import App from './App.jsx'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
