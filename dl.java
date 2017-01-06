import dl.Thunder;
import java.util.ArrayList;

// 041faff056f29ac6d78325e559cb6caf
public class dl {
    public static void main(String args[]) {
        Thunder thd = new Thunder(3, 1024);

        ArrayList<String> urls = new ArrayList<String>();
        urls.add("http://127.0.0.1:8080/1.php");
        urls.add("http://127.0.0.1:8081/1.php");
        urls.add("http://127.0.0.1:8082/1.php");

        thd.Flash(urls, "./test.zip", 2356768736l);

        while(true) {
            try {
                Thread.sleep(100);
            } catch (InterruptedException e) {
                e.printStackTrace(); 
            }
        }
    }



}