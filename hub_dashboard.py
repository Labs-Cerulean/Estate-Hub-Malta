import streamlit as st
import pandas as pd
import sqlite3

USERS = {'admin': 'Pra2026!', 'manager': 'Site2026!', 'viewer': 'View2026!'}

st.set_page_config(page_title="Estate Hub Malta", page_icon="logo.png", layout="wide")

# INLINE DATABASE CLASS - NO IMPORTS!
class Database:
    def __init__(self):
        self.conn = sqlite3.connect('estate_hub.db', check_same_thread=False)
        self.init_db()
    
    def init_db(self):
        self.conn.execute("""
            CREATE TABLE IF NOT EXISTS projects (
                id TEXT PRIMARY KEY, 
                name TEXT, 
                client TEXT, 
                city TEXT,
                pa_number TEXT DEFAULT '',
                bca_status TEXT DEFAULT 'AWAITING', 
                status TEXT DEFAULT 'Pending'
            )
        """)
        
        # Sample data from your Excel[file:142]
        if pd.read_sql("SELECT COUNT(*) FROM projects", self.conn).iloc[0,0] == 0:
            sample_projects = [
                ('P001', 'Dirjanu Supermarket', 'Agius', 'Ghajnsielem', '7246/22', 'DONE', 'Mobilised'),
                ('P002', 'Hotel All Season', 'Blue Clay', 'Victoria', '7298/24', 'AWAITING', 'In Process'),
                ('P003', 'Hotel Ghajnsielem', 'Blue Clay', 'Ghajnsielem', '753/25', 'AWAITING', 'Pending'),
                ('P004', 'Hotel Qala', 'Blue
